<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Stops and removes the project's containers (`kcode down [--volumes]`).
 *
 * @since 1.2.0
 */
final class DownCommand extends AbstractContainerCommand
{
    #[Override]
    public function name(): string
    {
        return 'down';
    }

    #[Override]
    public function description(): string
    {
        return 'Stop and remove the PHP container (--volumes to drop volumes)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $this->banner('KaririCode Devkit — Down');

        $runtime = $devkit->container();

        if (! $runtime->isConfigured()) {
            $this->warning('Nothing to stop: .kcode/docker/compose.yaml does not exist.');

            return 0;
        }

        $exitCode = $devkit->executor()->passthru(
            $runtime->down($this->hasFlag($arguments, '--volumes', '-v')),
            inContainer: false,
        );

        if (0 === $exitCode) {
            $this->info("Container removed: {$runtime->projectName()}");
        }

        return $exitCode;
    }
}
