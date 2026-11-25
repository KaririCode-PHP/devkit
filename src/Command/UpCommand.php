<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\Devkit;
use Override;

/**
 * Starts the project's PHP container (`kcode up [--build]`).
 *
 * Regenerates `.kcode/` first, so changes to devkit.php → docker apply.
 * The image is built on the first run and reused while the generated
 * Dockerfile is unchanged; `--build` forces a rebuild.
 *
 * @since 1.2.0
 */
final class UpCommand extends AbstractContainerCommand
{
    #[Override]
    public function name(): string
    {
        return 'up';
    }

    #[Override]
    public function description(): string
    {
        return 'Start the PHP container (--build to rebuild the image)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $this->banner('KaririCode Devkit — Up');

        $devkit->init();
        $runtime = $devkit->container();

        $exitCode = $devkit->executor()->passthru(
            $runtime->up($this->hasFlag($arguments, '--build')),
            inContainer: false,
        );

        if (0 !== $exitCode) {
            $this->error("docker compose up failed (exit {$exitCode}).");

            return $exitCode;
        }

        $this->info("Container running: {$runtime->projectName()}");
        $this->line("  Next: \033[1mkcode test --docker\033[0m · \033[1mkcode shell\033[0m · \033[1mkcode down\033[0m");

        return 0;
    }
}
