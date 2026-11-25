<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Runs an arbitrary command in the PHP container (`kcode exec <command>`).
 *
 * Examples: `kcode exec composer update`, `kcode exec php -m`,
 * `kcode exec -- php bin/console --help`. Starts the container when it is
 * not running and returns the command's exit code.
 *
 * @since 1.2.0
 */
final class ExecCommand extends AbstractContainerCommand
{
    #[Override]
    public function name(): string
    {
        return 'exec';
    }

    #[Override]
    public function description(): string
    {
        return 'Run a command in the PHP container (e.g. kcode exec composer update)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $command = $this->commandArguments($arguments);

        if ([] === $command) {
            $this->error('Usage: kcode exec <command> [arguments...]');

            return 1;
        }

        $runtime = $this->runtime($devkit);
        $executor = $this->containerExecutor($devkit, $runtime);

        $exitCode = $executor->ensureContainer();

        if (0 !== $exitCode) {
            return $exitCode;
        }

        return $executor->passthru($command, interactive: true);
    }
}
