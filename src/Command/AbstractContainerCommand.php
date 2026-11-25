<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use KaririCode\Devkit\Core\ContainerRuntime;
use KaririCode\Devkit\Core\Devkit;
use KaririCode\Devkit\Core\ProcessExecutor;

/**
 * Base for the commands that manage the project's PHP container
 * (`up`, `down`, `shell`, `exec`, `ps`, `logs`).
 *
 * They always act on the container, with or without `--docker`.
 *
 * @since 1.2.0
 */
abstract class AbstractContainerCommand extends AbstractCommand
{
    /** Container runtime with `.kcode/docker/` generated when missing. */
    protected function runtime(Devkit $devkit): ContainerRuntime
    {
        $runtime = $devkit->container();

        if (! $runtime->isConfigured()) {
            $devkit->init();
        }

        return $runtime;
    }

    /** Executor that wraps commands with `docker compose exec`, whatever the global mode. */
    protected function containerExecutor(Devkit $devkit, ContainerRuntime $runtime): ProcessExecutor
    {
        $executor = $devkit->executor();

        return $executor->isContainerized()
            ? $executor
            : new ProcessExecutor($executor->workingDirectory(), $runtime);
    }

    /**
     * Drop a leading `--` separator (kcode exec -- php -v).
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    protected function commandArguments(array $arguments): array
    {
        if ('--' === ($arguments[0] ?? null)) {
            array_shift($arguments);
        }

        return $arguments;
    }
}
