<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Shows the state of the project's containers (`kcode ps`).
 *
 * @since 1.2.0
 */
final class PsCommand extends AbstractContainerCommand
{
    #[Override]
    public function name(): string
    {
        return 'ps';
    }

    #[Override]
    public function description(): string
    {
        return 'Show the state of the project containers';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $runtime = $this->runtime($devkit);

        return $devkit->executor()->passthru($runtime->compose('ps', ...$arguments), inContainer: false);
    }
}
