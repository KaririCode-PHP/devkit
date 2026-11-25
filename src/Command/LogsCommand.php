<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Shows container logs (`kcode logs [--follow] [service]`).
 *
 * Arguments pass through to `docker compose logs`.
 *
 * @since 1.2.0
 */
final class LogsCommand extends AbstractContainerCommand
{
    #[Override]
    public function name(): string
    {
        return 'logs';
    }

    #[Override]
    public function description(): string
    {
        return 'Show container logs (--follow, or a service name)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $runtime = $this->runtime($devkit);

        return $devkit->executor()->passthru($runtime->compose('logs', ...$arguments), inContainer: false);
    }
}
