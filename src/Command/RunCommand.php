<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Runs any binary installed in the project toolchain (`kcode run <binary>`).
 *
 * Covers tools without a dedicated command — for example Infection or
 * ParaTest, added through devkit.php → tools:
 *
 *     kcode run infection --threads=max
 *
 * Looks in `.kcode/vendor/bin/` first, then in `vendor/bin/`. Respects
 * `--docker` and returns the binary's exit code.
 *
 * @since 1.2.0
 */
final class RunCommand extends AbstractCommand
{
    #[Override]
    public function name(): string
    {
        return 'run';
    }

    #[Override]
    public function description(): string
    {
        return 'Run a toolchain binary (e.g. kcode run infection --threads=max)';
    }

    #[Override]
    public function requiresToolchain(): bool
    {
        return true;
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        if ('--' === ($arguments[0] ?? null)) {
            array_shift($arguments);
        }

        $name = array_shift($arguments);

        if (null === $name || '' === $name || str_starts_with($name, '-')) {
            $this->error('Usage: kcode run <binary> [arguments...]');

            return 1;
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            $this->error("Pass a binary name, not a path: {$name}");

            return 1;
        }

        $context = $devkit->context();
        $candidates = [
            $context->configPath('vendor/bin/' . $name),
            $context->rootPath('vendor/bin/' . $name),
        ];

        foreach ($candidates as $binary) {
            if (is_file($binary) && is_executable($binary)) {
                return $devkit->executor()->passthru([$binary, ...$arguments], interactive: true);
            }
        }

        $this->error("Binary \"{$name}\" not found in .kcode/vendor/bin/ or vendor/bin/.");
        $this->line("  Add its package to devkit.php → 'tools' (e.g. 'infection/infection' => '^0.29').");

        return 127;
    }
}
