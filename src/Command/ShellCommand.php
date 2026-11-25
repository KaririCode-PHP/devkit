<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Opens an interactive shell in the PHP container (`kcode shell [shell]`).
 *
 * Starts the container when it is not running. The default shell is `sh`
 * (the image is Alpine-based); pass another one, e.g. `kcode shell bash`.
 *
 * @since 1.2.0
 */
final class ShellCommand extends AbstractContainerCommand
{
    private const string DEFAULT_SHELL = 'sh';

    #[Override]
    public function name(): string
    {
        return 'shell';
    }

    #[Override]
    public function description(): string
    {
        return 'Open a shell in the PHP container (default: sh)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $runtime = $this->runtime($devkit);
        $executor = $this->containerExecutor($devkit, $runtime);

        $exitCode = $executor->ensureContainer();

        if (0 !== $exitCode) {
            return $exitCode;
        }

        $shell = $this->positional($arguments)[0] ?? self::DEFAULT_SHELL;

        return $executor->passthru([$shell], interactive: true);
    }
}
