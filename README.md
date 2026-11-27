# KaririCode Devkit

<div align="center">

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Composer](https://img.shields.io/badge/Composer-2.x-885630?logo=composer&logoColor=white)](https://getcomposer.org/)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e.svg)](LICENSE)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-4F46E5)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/Tests-342%20passing-22c55e)](https://kariricode.org)
[![KaririCode Framework](https://img.shields.io/badge/KaririCode-Framework-orange)](https://kariricode.org)

**Unified quality toolchain and container runtime for the KaririCode Framework ecosystem.**

One global CLI. No QA dependencies, no Docker files, no config drift across 35+ components.

[Installation](#installation) · [Quick Start](#quick-start) · [CLI Reference](#cli-reference) · [Container Runtime](#container-runtime) · [Configuration](#configuration) · [CI Integration](#ci-integration)

</div>

---

## The Problem

Every KaririCode component used to maintain its own toolchain and its own container — hundreds of near-identical files drifting apart:

```
composer.json        →  5–7 QA entries in require-dev per component
phpunit.xml.dist     →  ~60 lines per component
phpstan.neon         →  ~25 lines per component
.php-cs-fixer.php    →  ~50 lines per component
.docker/php/         →  Dockerfile + php.ini per component
docker-compose.yml   →  one service per component
.env.example         →  KARIRI_PHP_VERSION, KARIRI_PHP_PORT, …
Makefile             →  ~170 lines wrapping docker-compose exec
```

Across **35+ components**, updating a CS-Fixer rule, a PHPUnit version or the PHP image means 35 pull requests.

## The Solution

Install `kcode` once. Inside any KaririCode component, every command provisions what it needs:

```bash
cd kariricode-validator/src      # any directory inside the component
kcode test                       # generates .kcode/, installs the toolchain, runs PHPUnit
kcode up                         # starts the shared PHP container (.kcode/docker/)
kcode quality --docker           # full pipeline inside the container
```

The component keeps only its source, its `composer.json` and, optionally, a `devkit.php`:

```
your-component/
├── devkit.php               ← Optional overrides (committed)
├── devkit.lock              ← Optional pinned tool versions (committed)
├── .kcode/                  ← Generated, disposable, gitignored
│   ├── composer.json        ←   toolchain manifest
│   ├── vendor/              ←   PHPUnit, PHPStan, PHP-CS-Fixer, Rector, Psalm (+ devkit.php 'tools')
│   ├── docker/              ←   Dockerfile, php.ini, compose.yaml
│   ├── phpunit.xml.dist     ←   generated configs
│   ├── phpstan.neon
│   ├── php-cs-fixer.php
│   ├── rector.php
│   ├── psalm.xml
│   └── build/               ←   coverage, caches, JUnit XML
├── composer.json
├── src/
└── tests/
```

```diff
- "require-dev": {
-     "phpunit/phpunit": "^11.0",
-     "phpstan/phpstan": "^1.10",
-     "friendsofphp/php-cs-fixer": "^3.51",
-     "squizlabs/php_codesniffer": "^3.9",
-     "mockery/mockery": "^1.6"
- },
+ "require-dev": {},
  "scripts": {
-     "test": "vendor/bin/phpunit --testdox"
+     "test": "kcode test --testdox"
  }
```

---

## Requirements

| Requirement | Version | Needed for |
|---|---|---|
| PHP | 8.4 or higher | `kcode` itself and host-mode tools |
| Composer | 2.x | Installing the toolchain into `.kcode/vendor/` |
| Docker with Compose v2 | any current | Only for `kcode up` and `--docker` |

---

## Installation

### Global PHAR *(recommended)*

```bash
wget https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
chmod +x kcode.phar
sudo mv kcode.phar /usr/local/bin/kcode
kcode --version
```

### Global Composer package

```bash
composer global require kariricode/devkit
# make sure Composer's global bin directory is on your PATH
```

### Project dependency *(legacy)*

`composer require --dev kariricode/devkit` still works and exposes `vendor/bin/kcode`, but it puts a QA dependency back into `require-dev`. Prefer the global install.

---

## Quick Start

### A component that already uses kcode

```bash
git clone https://github.com/KaririCode-PHP/dotenv && cd dotenv
kcode quality     # first run: configs + toolchain + composer install, then the pipeline
```

### A legacy component (`.docker/`, Makefile, QA tools in require-dev)

```bash
kcode migrate --dry-run    # review what kcode replaces
kcode migrate              # remove Docker scaffolding, QA packages, root configs; rewrite scripts
composer update            # drop the removed packages from composer.lock and vendor/
kcode quality              # provisions .kcode/ and runs the pipeline
```

Components that keep root configs under KaririCode Spec V4.3.3 §31 use `kcode migrate --keep-configs`, which also sets `'root_configs' => true` in `devkit.php`.

---

## CLI Reference

```
kcode <command> [options] [arguments]
```

| Command | Purpose |
|---|---|
| `init` | Generate `.kcode/` and install the toolchain (`--config`, `--lock`, `--skip-install`) |
| `migrate` | Remove Docker scaffolding, QA `require-dev` entries and root configs (`--dry-run`, `-n`, `--keep-configs`) |
| `test` | Run PHPUnit (`--coverage`, `--suite=Name`, any PHPUnit argument) |
| `analyse` | Run PHPStan and Psalm |
| `cs:fix` | Fix code style (`--check` for a dry run) |
| `rector` | Preview Rector changes (`--fix` to apply) |
| `quality` | Full pipeline: cs:check → phpstan → psalm → phpunit |
| `format` | cs:fix + rector --fix |
| `security` | `composer audit` |
| `run` | Run any toolchain binary: `kcode run infection --threads=max` |
| `up` | Start the PHP container (`--build` to rebuild the image) |
| `down` | Stop and remove the containers (`--volumes`) |
| `shell` | Open a shell in the container (`kcode shell bash`) |
| `exec` | Run a command in the container: `kcode exec composer update` |
| `ps` / `logs` | Container state / logs (`kcode logs --follow`) |
| `clean` | Remove `.kcode/build/` (`--all` removes the whole `.kcode/`) |

Global options: `--docker` runs the command's tools inside the PHP container; `--no-docker` forces the host. Every command works from any subdirectory of the project.

---

### `kcode init`

Generates all configs inside `.kcode/` and installs the toolchain into `.kcode/vendor/`. Inside a KaririCode component this happens automatically on the first tool command, so `init` is optional there.

```bash
kcode init                 # Generate configs and install the toolchain
kcode init --config        # Also scaffold devkit.php with every supported key
kcode init --lock          # Pin the installed tool versions in devkit.lock (commit it)
kcode init --skip-install  # Configs only
```

---

### `kcode migrate`

Takes a component to "source plus kcode". Presents a full report before changing anything.

```bash
kcode migrate                   # Interactive (default)
kcode migrate --dry-run         # Report only
kcode migrate --no-interaction  # Apply everything (CI)
kcode migrate --keep-configs    # Keep root tool configs (Spec V4.3.3 §31)
```

| Category | Items | Action |
|---|---|---|
| Docker scaffolding | `.docker/`, `docker/`, `docker-compose*.y(a)ml`, `compose.y(a)ml`, `Dockerfile`, `.dockerignore`, the legacy `.env.example` and Docker `Makefile` | Removed (`kcode up` replaces them) |
| QA tools in `require-dev` | PHPUnit, PHPStan (+ extensions), PHP-CS-Fixer, Rector, Psalm (+ PHPUnit plugin) | Removed (installed into `.kcode/vendor/`) |
| Superseded QA tools | PHP_CodeSniffer, PHP Insights, Security Checker, PHPMD, phpcpd, ECS | Removed |
| Test-support libraries | Mockery, Infection, ParaTest, prophecy-phpunit, bypass-finals | Moved to `devkit.php` → `tools` |
| Root configs | `phpunit.xml(.dist)`, `phpstan.neon(.dist)`, `.php-cs-fixer(.dist).php`, `rector.php`, `psalm.xml(.dist)` | Removed, or kept with `--keep-configs` (which enables `root_configs`) |
| Superseded configs | `phpcs.xml`, `phpinsights.php`, `phpmd.xml`, `ecs.php`, … | Removed |
| Caches | `.phpunit.cache`, `.phpstan`, `.php-cs-fixer.cache`, `.psalm`, `.phpcs-cache`, `coverage-report-html/` | Removed |
| Composer scripts | Calls to `phpunit`, `phpstan`, `psalm`, `php-cs-fixer`, `phpcs`, `rector`, `infection`, … | Rewritten to `kcode` commands |

`.env.example` and `Makefile` are removed only when they match the legacy Docker templates. composer.json keeps its indentation, and `"require-dev": {}` stays an object.

---

### `kcode test`

Runs PHPUnit with the root `phpunit.xml(.dist)` when the project commits one, otherwise with `.kcode/phpunit.xml.dist`.

```bash
kcode test                           # Run all test suites
kcode test --suite=Unit              # Run a single suite
kcode test --coverage                # HTML coverage report in .kcode/build/coverage/
kcode test --filter=testMyMethod     # Pass any PHPUnit argument through
kcode test --docker                  # Run inside the PHP container
```

Without PCOV or Xdebug (coverage mode), coverage reporting is switched off automatically so the tests still run.

---

### `kcode analyse` · `kcode cs:fix` · `kcode rector` · `kcode format`

```bash
kcode analyse         # PHPStan, then Psalm
kcode cs:fix          # Fix all violations
kcode cs:fix --check  # Report only
kcode rector          # Preview
kcode rector --fix    # Apply
kcode format          # cs:fix + rector --fix
```

---

### `kcode quality`

```
cs:check → phpstan → psalm → phpunit
```

```
✓ cs-fixer   passed (1.23s)
✓ phpstan    passed (4.56s)
✓ psalm      passed (3.21s)
✓ phpunit    passed (2.10s)

✓ All 4 tool(s) passed (11.10s total)
```

---

### `kcode run`

Runs a binary from `.kcode/vendor/bin/` (then `vendor/bin/`) — for tools added through `devkit.php` → `tools` that have no dedicated command:

```bash
kcode run infection --threads=max --show-mutations
kcode run paratest --processes=4
```

---

### `kcode security` · `kcode clean`

```bash
kcode security     # composer audit
kcode clean        # Remove .kcode/build/
kcode clean --all  # Remove .kcode/ entirely — recreated on the next run
```

---

## On-Demand Provisioning

Before `test`, `analyse`, `cs:fix`, `rector`, `format`, `quality` and `run`, kcode prepares `.kcode/` when the project is a KaririCode component (`kariricode/*` package, or any project with a `devkit.php`) or already has a `.kcode/`:

| Step | Runs when |
|---|---|
| Regenerate configs | `composer.json`, `devkit.php` or the kcode version changed, or a generated file is missing |
| Start the container | `--docker` is active and the container is stopped |
| Install the toolchain | `.kcode/vendor/` is missing, or `.kcode/composer.json` / `devkit.lock` changed |
| `composer install` | `vendor/autoload.php` is missing |

```
⚙ Generated 9 config file(s) in .kcode/
⚙ Installing the toolchain into .kcode/vendor/ ...
⚙ Installing project dependencies (composer install) ...
```

Later runs skip every step. Set `KCODE_NO_PROVISION=1` to disable provisioning. See [ADR-008](docs/adr/ADR-008-on-demand-provisioning.md).

**Pinned versions.** `kcode init --lock` writes `devkit.lock`. When it is committed, every machine and CI installs exactly those tool versions.

---

## Container Runtime

`kcode up` renders `.kcode/docker/` from `devkit.php` → `docker` and starts it. One definition serves every component; nothing Docker-related is committed.

```bash
kcode up                        # build on first run, then start (docker compose up --detach --wait)
kcode test --docker             # any tool command, inside the container
kcode exec composer update      # any command, inside the container
kcode shell                     # interactive sh
XDEBUG_MODE=debug kcode test --docker --filter=testParser   # Xdebug for one run
kcode down
```

| Property | Behaviour |
|---|---|
| Image | `php:<version>-cli-alpine` + PCOV, Xdebug (off), intl, zip + Composer 2 + git |
| Tag | `kcode-php:<php>-<Dockerfile hash>` — identical settings share one image |
| Mount | The project at its **host path**, so every path kcode computes is valid inside |
| User | Commands run as your UID:GID, so files keep your ownership |
| Start | Automatic with `--docker`; `kcode up` / `kcode down` for explicit control |

`--docker` can be the default per project (`'docker' => ['enabled' => true]`) or per shell (`KCODE_DOCKER=1`). Use `KCODE_DOCKER_BINARY=podman` for another Compose-compatible CLI. Native Windows paths are not supported; use WSL2. See [ADR-007](docs/adr/ADR-007-container-runtime.md).

---

## Configuration

### Auto-detection

| Detected Field | Source |
|---|---|
| Project root | Nearest `composer.json` from the current directory upwards |
| Project name | `name` |
| Root namespace | `autoload.psr-4` (first key) |
| PHP version | `require.php` |
| Source directories | `autoload.psr-4` values |
| Test directories | `autoload-dev.psr-4` values |
| Test suites | Standard subdirs: `Unit/`, `Integration/`, `Conformance/`, `Functional/` |

### Root configs (opt-in)

By default kcode runs every tool with the generated `.kcode/` config, even when the project still has root configs. With `'root_configs' => true` in `devkit.php`, a committed `phpunit.xml(.dist)`, `phpstan.neon(.dist)`, `psalm.xml(.dist)`, `.php-cs-fixer(.dist).php` or `rector.php` wins instead; the binaries still come from `.kcode/vendor/`. This keeps components under KaririCode Spec V4.3.3 §31 on their own gates. `kcode migrate --keep-configs` sets the key. See [ADR-009](docs/adr/ADR-009-root-config-precedence.md).

1.2.0 applied root configs without the opt-in; stale root configs left in migrated components (for example `.php-cs-fixer.php` in kariricode/normalizer and kariricode/validator) then replaced the generated rules and failed `kcode quality`. 1.2.1 restored the generated configs as the default.

### Project overrides via `devkit.php`

```bash
kcode init --config    # scaffolds a fully annotated devkit.php
```

```php
<?php

declare(strict_types=1);

return [
    'phpstan_level'    => 8,
    'exclude_dirs'     => ['src/Contract'],
    'coverage_exclude' => ['src/Exception'],
    'cs_fixer_rules'   => ['yoda_style' => false],      // merged with KaririCode defaults

    // Toolchain: any Composer package joins .kcode/vendor/
    'tools' => [
        'phpunit'         => '^12.5',
        'mockery/mockery' => '^1.6',
    ],

    // Container runtime (kcode up / --docker)
    'docker' => [
        'enabled'    => false,
        'extensions' => ['pcov', 'xdebug', 'intl', 'zip', 'redis'],
        'services'   => ['redis' => 'redis:7-alpine'],
        'ini'        => ['memory_limit' => '512M'],
    ],
];
```

Changes apply on the next command — no manual `kcode init` needed inside components.

### Override reference

| Key | Type | Default | Merge strategy |
|---|---|---|---|
| `project_name` | `string` | From `composer.json` | Replace |
| `namespace` | `string` | From PSR-4 autoload | Replace |
| `php_version` | `string` | From `require.php` | Replace |
| `phpstan_level` | `int` | `9` | Replace |
| `psalm_level` | `int` | `3` | Replace |
| `source_dirs` | `list<string>` | From PSR-4 autoload | Replace |
| `test_dirs` | `list<string>` | From PSR-4 autoload-dev | Replace |
| `exclude_dirs` | `list<string>` | `['src/Contract']` | Replace |
| `test_suites` | `array<string, string>` | Auto-detected | Replace |
| `coverage_exclude` | `list<string>` | `[]` | Replace |
| `cs_fixer_rules` | `array<string, mixed>` | KaririCode standard | **Merge** (your rules win) |
| `rector_sets` | `list<string>` | KaririCode standard | Replace |
| `tools` | `array<string, string>` | PHPUnit, PHPStan, PHP-CS-Fixer, Rector, Psalm | **Merge** (short names or package names) |
| `root_configs` | `bool` | `false` | Replace — `true` makes committed root tool configs win |
| `docker.enabled` | `bool` | `false` | Replace |
| `docker.php_version` | `string` | `php_version` | Replace |
| `docker.image` | `?string` | `null` (build `.kcode/docker/Dockerfile`) | Replace |
| `docker.extensions` | `list<string>` | `pcov, xdebug, intl, zip` | Replace |
| `docker.packages` | `list<string>` | `[]` | Replace |
| `docker.ini` | `array<string, scalar>` | memory, timezone, Xdebug off | **Merge** |
| `docker.environment` | `array<string, scalar>` | HOME, Composer, git safe.directory | **Merge** |
| `docker.ports` | `list<string>` | `[]` | Replace |
| `docker.services` | `array<string, string\|array>` | `[]` | Replace |
| `docker.service` | `array<string, mixed>` | `[]` | Maps merge, lists append, scalars replace |

### Environment variables

| Variable | Effect |
|---|---|
| `KCODE_DOCKER=1` / `0` | Default for `--docker` / `--no-docker` |
| `KCODE_NO_PROVISION=1` | Disable on-demand provisioning |
| `KCODE_DOCKER_BINARY` | Container CLI (default `docker`) |
| `COMPOSER_BINARY` | Composer executable on the host |
| `XDEBUG_MODE`, `XDEBUG_CONFIG`, `XDEBUG_SESSION` | Forwarded into the container per command |

### File ownership

| File | Location | Committed | Managed by |
|---|---|---|---|
| `devkit.php` | Project root | ✅ Yes | Developer |
| `devkit.lock` | Project root | ✅ Yes (optional) | `kcode init --lock` |
| `.kcode/` | Project root | ❌ No (gitignored) | kcode |

### KaririCode coding standard

The default CS-Fixer ruleset includes PSR-12 with PHP 8.4 migration rules, strict types, compiler-optimized native function invocations, alphabetically ordered imports and trailing commas in multiline structures. See [SPEC-001](docs/spec/SPEC-001-project-detection.md) §7. Components with a root `.php-cs-fixer.dist.php` use their own rules.

---

## CI Integration

### GitHub Actions — unified pipeline

```yaml
name: Quality
on: [push, pull_request]
jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: pcov
          tools: composer:v2
      - name: Install kcode
        run: |
          wget -q https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
          sudo install -m 0755 kcode.phar /usr/local/bin/kcode
      - run: kcode quality    # provisions .kcode/ and runs composer install when needed
```

Pin the toolchain with a committed `devkit.lock` for reproducible gates, and pin the PHAR to a release tag instead of `latest` when the workflow must not change on its own.

---

## Architecture

### Component layout

```
src/
├── Contract/        Interfaces: ConfigGenerator, ToolRunner
├── Core/            Devkit façade · ProjectLocator · ProjectDetector · ProcessExecutor ·
│                    ContainerRuntime · ToolchainState · Migration services
├── Configuration/   Config generators (5 tools + toolchain manifest + Docker runtime)
├── Runner/          Tool runners (7 runners + AbstractToolRunner)
├── Command/         CLI commands (17 commands + Application + base classes)
├── Exception/       Exception hierarchy
└── ValueObject/     Immutable values: ToolResult · QualityReport · MigrationReport · DockerSettings
```

### Dependency flow

```
Command → Core (Devkit) → Contract ← Runner / Configuration
```

Strict unidirectional flow. Commands call the `Devkit` façade. Runners and generators implement contracts. Core orchestrates and never imports Configuration.

### Key design decisions

| Decision | Rationale | ADR |
|---|---|---|
| Native PHAR builder | Avoids Box 4.x / PHP 8.4 incompatibility | — |
| Zero external runtime dependencies | Sub-millisecond boot, no version conflicts | [ADR-002](docs/adr/ADR-002-zero-dependencies.md) |
| Config generation over bundling | Eliminates drift across 35+ components | [ADR-003](docs/adr/ADR-003-config-generation.md) |
| Three-tier binary resolution | `.kcode/vendor` → `vendor` → global fallback | [ADR-004](docs/adr/ADR-004-binary-resolution.md) |
| `.kcode/` directory | Clean project root, single gitignore entry | [ADR-005](docs/adr/ADR-005-kariricode-directory.md) |
| Immutable value objects | ARFA 1.3 compliant | [ADR-006](docs/adr/ADR-006-immutable-value-objects.md) |
| Generated container runtime | One Docker definition for every component | [ADR-007](docs/adr/ADR-007-container-runtime.md) |
| On-demand provisioning | A global kcode needs no per-project setup | [ADR-008](docs/adr/ADR-008-on-demand-provisioning.md) |
| Root configs on opt-in (`root_configs`) | Spec V4.3.3 §31 components keep their gates; stale root configs cannot override | [ADR-009](docs/adr/ADR-009-root-config-precedence.md) |

### Specifications

| Spec | Covers |
|---|---|
| [SPEC-001](docs/spec/SPEC-001-project-detection.md) | Project location and detection, config merging, Docker settings |
| [SPEC-002](docs/spec/SPEC-002-cli-interface.md) | CLI interface, global options, toolchain preparation |
| [SPEC-003](docs/spec/SPEC-003-tool-runner.md) | Runner contract, config precedence, container mode, process execution |

---

## Project Stats

| Metric | Value |
|---|---|
| PHP source files | 61 |
| Total source lines | ~6,200 |
| External runtime dependencies | 0 |
| Quality tools supported | 6 (PHPUnit, PHPStan, PHP-CS-Fixer, Rector, Psalm, Composer Audit) + any via `kcode run` |
| CLI commands | 17 |
| PHPStan level | 9 (0 errors) |
| Test suite | 342 tests · 696 assertions (Unit + Integration) |
| PHP version | 8.4+ |
| ARFA compliance | 1.3 |

---

## Building `kcode.phar`

```bash
# Requirements: PHP 8.4+ · Composer 2.x · phar.readonly=0

# Full release pipeline (recommended)
make release

# Manual
composer install
php -d phar.readonly=0 bin/build-phar.php
php build/kcode.phar --version    # → KaririCode Devkit 1.2.1

# Install globally
sudo mv build/kcode.phar /usr/local/bin/kcode
```

See [docs/BUILDING.md](docs/BUILDING.md) for full build documentation, troubleshooting, and release automation details.

---

## Contributing

```bash
git clone https://github.com/KaririCode-PHP/devkit.git
cd devkit
composer install
php bin/kcode quality        # Must pass before opening a PR
```

Commit messages follow Conventional Commits in English: `type(scope): imperative summary` (`feat`, `fix`, `refactor`, `test`, `docs`, `build`, `ci`, `chore`, `perf`, `style`), lowercase after the colon, no trailing period, with a body that explains why and lists the changes.

CI enforces code quality and a PHAR smoke test on every push and PR.

---

## License

[MIT License](LICENSE) © [Walmir Silva](mailto:walmir.silva@kariricode.org)

---

<div align="center">

Part of the **[KaririCode Framework](https://kariricode.org)** ecosystem.

[kariricode.org](https://kariricode.org) · [GitHub](https://github.com/KaririCode-PHP) · [Packagist](https://packagist.org/packages/kariricode/)

</div>
