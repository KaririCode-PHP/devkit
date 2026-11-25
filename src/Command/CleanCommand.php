<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Removes `.kcode/build/` directory (caches, coverage, reports).
 *
 * `--all` removes the whole `.kcode/` directory — configs, the installed
 * toolchain and build output. kcode recreates it on the next run.
 *
 * @since 1.0.0
 */
final class CleanCommand extends AbstractCommand
{
    #[Override]
    public function name(): string
    {
        return 'clean';
    }

    #[Override]
    public function description(): string
    {
        return 'Remove .kcode/build/ (--all removes the whole .kcode/)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $this->banner('KaririCode Devkit — Clean');

        if ($this->hasFlag($arguments, '--all')) {
            $devkit->purge();
            $this->info('Removed .kcode/ — it is recreated on the next kcode run.');

            return 0;
        }

        $devkit->clean();

        $this->info('Build directory cleaned: .kcode/build/');

        return 0;
    }
}
