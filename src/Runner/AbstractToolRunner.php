<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Runner;

use KaririCode\Devkit\Contract\ToolRunner;
use KaririCode\Devkit\Core\ProcessExecutor;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\ValueObject\ToolResult;
use Override;

/**
 * Base runner: resolves binary via three-tier strategy and delegates
 * execution to ProcessExecutor. Subclasses define tool identity and
 * default arguments.
 *
 * @since 1.0.0
 */
abstract class AbstractToolRunner implements ToolRunner
{
    private ?string $resolvedBinary = null;

    public function __construct(
        protected readonly ProcessExecutor $executor,
        protected readonly ProjectContext $context,
    ) {
    }

    /** Vendor-relative binary path (e.g. "vendor/bin/phpunit"). */
    abstract protected function vendorBin(): string;

    /**
     * Default arguments prepended before user-supplied arguments.
     *
     * @return list<string>
     */
    abstract protected function defaultArguments(): array;

    #[Override]
    public function isAvailable(): bool
    {
        return null !== $this->binary();
    }

    /** @param list<string> $arguments */
    #[Override]
    public function run(array $arguments = []): ToolResult
    {
        $binary = $this->binary();

        if (null === $binary) {
            return new ToolResult(
                toolName: $this->toolName(),
                exitCode: 127,
                stdout: '',
                stderr: \sprintf('Binary not found for "%s".', $this->toolName()),
                elapsedSeconds: 0.0,
            );
        }

        $command = [$binary, ...$this->defaultArguments(), ...$arguments];

        return $this->executor->execute($this->toolName(), $command);
    }

    protected function binary(): ?string
    {
        return $this->resolvedBinary ??= $this->executor->resolveBinary($this->vendorBin());
    }

    /**
     * Configuration file the tool should use.
     *
     * The generated `.kcode/` config, unless the project opted in with
     * devkit.php → `'root_configs' => true`: then a config committed at the
     * project root wins (KaririCode Spec V4.3.3 §31), while the tools still
     * come from `.kcode/vendor/`. Candidates are checked in order, mirroring
     * each tool's own lookup.
     *
     * Opt-in since 1.2.1: in 1.2.0 root configs always won, and stale root
     * configs left in migrated components silently replaced the generated
     * rules (ADR-009).
     *
     * @since 1.2.0
     */
    protected function configFile(string $generated, string ...$rootCandidates): string
    {
        if (! $this->context->rootConfigs) {
            return $this->context->configPath($generated);
        }

        foreach ($rootCandidates as $candidate) {
            $path = $this->context->rootPath($candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return $this->context->configPath($generated);
    }
}
