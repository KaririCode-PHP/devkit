# SPEC-003: Tool Runner Abstraction and Process Execution

**Version:** 1.2.0
**Status:** Normative
**Date:** 2025-02-28 (revised 2026-11-25)
**Author:** Walmir Silva

## 1. Purpose

This specification defines the tool runner contract, process execution model, binary resolution strategy, and result capture semantics.

## 2. Architecture

```
┌──────────────┐     ┌──────────────────┐     ┌────────────────┐
│   Command    │────▸│  Devkit (facade)  │────▸│  ToolRunner    │
│              │     │                  │     │  (interface)    │
└──────────────┘     └──────────────────┘     └───────┬────────┘
                                                      │
                                              ┌───────▾────────┐
                                              │ AbstractTool-   │
                                              │ Runner (base)   │
                                              └───────┬────────┘
                                                      │ delegates
                                              ┌───────▾────────┐
                                              │ ProcessExecutor │
                                              │ (proc_open)     │
                                              └───────┬────────┘
                                                      │ returns
                                              ┌───────▾────────┐
                                              │   ToolResult    │
                                              │ (value object)  │
                                              └────────────────┘
```

## 3. ToolRunner Contract

```php
interface ToolRunner
{
    public function toolName(): string;
    public function isAvailable(): bool;
    public function run(array $arguments = []): ToolResult;
}
```

### 3.1 Behavioral Requirements

| Method | Requirement |
|---|---|
| `toolName()` | Returns a stable, unique identifier used as registry key |
| `isAvailable()` | Returns `true` if and only if the binary can be resolved |
| `run()` | Always returns a `ToolResult` — never throws for tool failures |

### 3.2 Exception Policy

`run()` must not throw exceptions for tool execution failures. All failure states are captured in `ToolResult`:

| Failure | ToolResult |
|---|---|
| Binary not found | `exitCode: 127, stderr: 'Binary not found...'` |
| Tool exits non-zero | `exitCode: N, stdout/stderr: tool output` |
| Process spawn failure | `exitCode: 127, stderr: 'Failed to spawn process...'` |

Exceptions are only thrown for programming errors (e.g., unknown tool name in `Devkit::run()`).

## 4. AbstractToolRunner

### 4.1 Template Method Pattern

Concrete runners implement three abstract methods:

```php
abstract public function toolName(): string;
abstract protected function vendorBin(): string;
abstract protected function defaultArguments(): array;
```

The base class provides `isAvailable()` and `run()`:

```
run(arguments) → binary() → [binary, ...defaultArguments(), ...arguments] → executor.execute()
```

### 4.2 Binary Caching

```php
protected function binary(): ?string
{
    return $this->resolvedBinary ??= $this->executor->resolveBinary($this->vendorBin());
}
```

Resolution happens once per process. The null-coalescing assignment operator (`??=`) ensures thread-safe lazy initialization in the single-threaded CLI context.

### 4.3 Registered Runners

| Runner | Tool Name | Vendor Binary | Default Args |
|---|---|---|---|
| `PhpUnitRunner` | `phpunit` | `vendor/bin/phpunit` | `--configuration .kcode/phpunit.xml.dist` |
| `PhpStanRunner` | `phpstan` | `vendor/bin/phpstan` | `analyse --configuration ... --no-progress --memory-limit=1G` |
| `CsFixerRunner` | `cs-fixer` | `vendor/bin/php-cs-fixer` | `fix --config ... --diff --ansi` |
| `RectorRunner` | `rector` | `vendor/bin/rector` | `process --config ... --dry-run --ansi` |
| `PsalmRunner` | `psalm` | `vendor/bin/psalm` | `--config ... --no-progress --show-info=false` |
| `ComposerAuditRunner` | `composer-audit` | `vendor/bin/composer` | `audit --format=plain --ansi` |

### 4.4 ComposerAuditRunner Override

Composer is typically global. The runner overrides `binary()` to check global PATH before vendor:

```
1. Global PATH (command -v composer)
2. Vendor binary (parent::binary())
```

This inverts the standard Tier 2 → Tier 3 order because `vendor/bin/composer` rarely exists.

### 4.5 Configuration Precedence (since 1.2.0)

`AbstractToolRunner::configFile($generated, ...$rootCandidates)` returns the first root candidate that exists, else `.kcode/$generated` (ADR-009):

| Runner | Root candidates |
|---|---|
| `PhpUnitRunner` | `phpunit.xml`, `phpunit.xml.dist` |
| `PhpStanRunner` | `phpstan.neon`, `phpstan.neon.dist`, `phpstan.dist.neon` |
| `PsalmRunner` | `psalm.xml`, `psalm.xml.dist` |
| `CsFixerRunner` | `.php-cs-fixer.php`, `.php-cs-fixer.dist.php` |
| `RectorRunner`, `RectorApplyRunner` | `rector.php` |

### 4.6 Coverage Driver Probe (since 1.2.0)

PHPUnit runs no test when a coverage report is configured and no driver is loaded. `PhpUnitRunner::run()` appends `--no-coverage` when the PHP that runs the tests has neither PCOV (`pcov.enabled`) nor Xdebug with `coverage` in its mode, unless the arguments already contain `--coverage*` or `--no-coverage`. In container mode the probe executes inside the container.

## 5. ProcessExecutor

### 5.1 Process Spawning

```php
proc_open($command, $descriptors, $pipes, $workingDirectory)
```

| Descriptor | Direction | Purpose |
|---|---|---|
| 0 (stdin) | `['pipe', 'r']` | Closed immediately (no interactive input) |
| 1 (stdout) | `['pipe', 'w']` | Captured via `stream_get_contents()` |
| 2 (stderr) | `['pipe', 'w']` | Captured via `stream_get_contents()` |

### 5.2 Timing

```php
$start = hrtime(true);
// ... process execution ...
$elapsed = (hrtime(true) - $start) / 1_000_000_000;
```

`hrtime(true)` returns nanoseconds as an integer. Division by 10^9 converts to seconds. Result is rounded to 3 decimal places (millisecond precision).

### 5.3 Binary Resolution (Three-Tier)

See ADR-004 for full rationale.

```php
resolveBinary(string $vendorBin): ?string
```

| Tier | Check | Example Path |
|---|---|---|
| 1 | `Phar::running(true)/$vendorBin` | `phar:///kcode.phar/vendor/bin/phpunit` |
| 2 | `$workingDirectory/$vendorBin` | `/project/vendor/bin/phpunit` |
| 3 | `command -v $basename` | `/usr/local/bin/phpunit` |

Tier 1 is only checked when running inside a PHAR (`Phar::running(false) !== ''`).

### 5.4 Container Mode (since 1.2.0)

`ProcessExecutor` accepts an optional `ContainerRuntime`. When present:

- `execute()` and `passthru()` wrap commands as `docker compose --file .kcode/docker/compose.yaml --project-name kcode-<slug> exec -T [--user uid:gid] [--env XDEBUG_*] --workdir <root> php <command>` (`-T` is dropped for interactive sessions on a TTY);
- `ensureContainer()` runs `docker compose ps --status running --quiet php` and, when it prints nothing, `docker compose up --detach --wait`;
- `resolveBinary()` skips the global PATH tier, which does not exist inside the container.

`execute(..., inContainer: false)` / `passthru(..., inContainer: false)` run on the host (used for the compose commands themselves).

### 5.5 Output Capture

`execute()` reads stdout and stderr concurrently (`stream_select()` on non-blocking pipes). Sequential reads deadlock as soon as a child fills the other pipe's buffer. `passthru()` attaches STDIN, STDOUT and STDERR directly.

### 5.6 Security

- `escapeshellarg()` is used for all shell-injected values in `command -v` calls.
- `proc_open()` accepts command as an array (no shell interpolation).

## 6. ToolResult

### 6.1 Structure

```php
final readonly class ToolResult
{
    public bool $success;

    public function __construct(
        public string $toolName,
        public int    $exitCode,
        public string $stdout,
        public string $stderr,
        public float  $elapsedSeconds,
    );
}
```

### 6.2 Derived Property

`$success = (0 === $exitCode)` — computed in constructor, immutable.

### 6.3 Combined Output

```php
public function output(): string
```

Returns `trim(stdout + "\n" + stderr)`. Falls back to `'(no output)'` when both streams are empty.

## 7. QualityReport

### 7.1 Aggregation

```php
final readonly class QualityReport
{
    public bool  $passed;        // all results successful
    public float $totalSeconds;  // sum of elapsed times
    public int   $failureCount;  // count of failed results

    public function __construct(public array $results);
    public function failures(): array;  // filtered failed results
}
```

### 7.2 Pipeline Semantics

The quality pipeline always completes all tools. `$passed` reflects the aggregate. Individual tool results are accessible via `$results` for detailed reporting.

## 8. Argument Flow

Complete argument flow from CLI to tool binary:

```
User CLI input:
  kcode test --coverage --suite=Unit --verbose

↓ Application strips "test"

Command receives:
  ['--coverage', '--suite=Unit', '--verbose']

↓ TestCommand processes

  extraArgs:    ['--coverage-html', '.kcode/build/coverage', '--testsuite', 'Unit']
  passthrough:  ['--verbose']   (--coverage and --suite=Unit consumed)

↓ Devkit::run('phpunit', allArgs)

Runner prepends defaults:
  ['vendor/bin/phpunit', '--configuration', '.kcode/phpunit.xml.dist',
   '--coverage-html', '.kcode/build/coverage', '--testsuite', 'Unit', '--verbose']

↓ ProcessExecutor::execute()

proc_open() receives full command array
```
