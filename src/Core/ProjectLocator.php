<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

use const DIRECTORY_SEPARATOR;

use KaririCode\Devkit\Exception\DevkitException;

/**
 * Locates the project root for a globally installed `kcode`.
 *
 * `kcode` may be invoked from any directory inside a project (for example
 * `src/Rule/`). The locator walks up the directory tree until it finds the
 * nearest `composer.json`, so every command operates on the project root
 * regardless of the current working directory.
 *
 * Directories inside `.kcode/` are skipped: `.kcode/composer.json` is the
 * generated toolchain manifest, never a project manifest.
 *
 * @since 1.2.0
 */
final readonly class ProjectLocator
{
    private const string MANIFEST = 'composer.json';
    private const string DEVKIT_DIRECTORY = '.kcode';

    /**
     * Return the absolute path of the nearest directory containing `composer.json`.
     *
     * @throws DevkitException When no `composer.json` exists up to the filesystem root.
     */
    public function locate(string $startDirectory): string
    {
        $directory = $this->outsideDevkitDirectory(realpath($startDirectory) ?: $startDirectory);

        while (true) {
            if (is_file($directory . DIRECTORY_SEPARATOR . self::MANIFEST)) {
                return $directory;
            }

            $parent = \dirname($directory);

            if ($parent === $directory) {
                throw DevkitException::projectNotDetected($startDirectory);
            }

            $directory = $parent;
        }
    }

    /** Move a path that points into `.kcode/` back to the directory that owns `.kcode/`. */
    private function outsideDevkitDirectory(string $directory): string
    {
        $segments = explode(DIRECTORY_SEPARATOR, $directory);
        $position = array_search(self::DEVKIT_DIRECTORY, $segments, true);

        if (false === $position) {
            return $directory;
        }

        $owner = implode(DIRECTORY_SEPARATOR, \array_slice($segments, 0, $position));

        return '' === $owner ? DIRECTORY_SEPARATOR : $owner;
    }
}
