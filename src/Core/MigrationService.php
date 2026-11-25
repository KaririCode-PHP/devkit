<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

use const DIRECTORY_SEPARATOR;

use FilesystemIterator;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use KaririCode\Devkit\ValueObject\MigrationReport;

use const PHP_EOL;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * Executes the I/O side-effects of a migration: removes files,
 * cache paths, Docker scaffolding, redundant packages and rewrites
 * Composer scripts.
 *
 * Extracted from MigrationReport to preserve the Value Object's
 * immutability (ARFA 1.3 S7) and enforce Single Responsibility (S1).
 *
 * composer.json is decoded into objects, so empty objects such as
 * `"require-dev": {}` or `"extra": {}` survive the rewrite.
 *
 * @since 1.0.0
 */
final class MigrationService
{
    public function __construct(
        private readonly DevkitConfigWriter $configWriter = new DevkitConfigWriter(),
    ) {
    }

    /** Remove config files and cache paths. */
    public function removeFiles(MigrationReport $report): int
    {
        return $this->removePaths($report->projectRoot, [...$report->redundantConfigFiles, ...$report->redundantCachePaths]);
    }

    /**
     * Remove the per-component Docker scaffolding (`.docker/`, compose files,
     * legacy `.env.example` and Makefile).
     *
     * @since 1.2.0
     */
    public function removeDockerScaffolding(MigrationReport $report): int
    {
        return $this->removePaths($report->projectRoot, $report->legacyDockerPaths);
    }

    /**
     * Remove redundant packages from composer.json require-dev.
     *
     * @return list<string> Package names actually removed.
     */
    public function removePackagesFromComposer(MigrationReport $report): array
    {
        return $this->rewriteComposer($report, array_keys($report->redundantPackages), [])['packages'];
    }

    /**
     * Apply every composer.json change of the report in a single write:
     * drop redundant packages, drop relocated packages (only when devkit.php
     * now lists them) and rewrite scripts.
     *
     * @return array{packages: list<string>, scripts: list<string>, relocated: bool}
     *
     * @since 1.2.0
     */
    public function updateComposerManifest(MigrationReport $report): array
    {
        $relocated = $this->configWriter->addTools($report->projectRoot, $report->relocatedPackages);

        $packages = array_keys($report->redundantPackages);

        if ($relocated) {
            $packages = [...$packages, ...array_keys($report->relocatedPackages)];
        }

        $scripts = [];

        foreach ($report->scriptRewrites as $name => $rewrite) {
            $scripts[$name] = $rewrite['to'];
        }

        return [...$this->rewriteComposer($report, $packages, $scripts), 'relocated' => $relocated];
    }

    /**
     * @param list<string>                       $packages Packages to drop from require-dev
     * @param array<string, string|list<string>> $scripts  Script name → new definition
     * @return array{packages: list<string>, scripts: list<string>}
     */
    private function rewriteComposer(MigrationReport $report, array $packages, array $scripts): array
    {
        $none = ['packages' => [], 'scripts' => []];
        $composerManifestPath = $report->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';

        if (! is_file($composerManifestPath)) {
            return $none;
        }

        $raw = file_get_contents($composerManifestPath);

        if (false === $raw) {
            return $none;
        }

        $composer = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

        if (! $composer instanceof stdClass) {
            return $none;
        }

        $removed = [];
        $requireDev = $composer->{'require-dev'} ?? null;

        if ($requireDev instanceof stdClass) {
            foreach ($packages as $package) {
                if (property_exists($requireDev, $package)) {
                    unset($requireDev->{$package});
                    $removed[] = $package;
                }
            }
        }

        $rewritten = [];
        $definitions = $composer->scripts ?? null;

        if ($definitions instanceof stdClass) {
            foreach ($scripts as $name => $definition) {
                if (property_exists($definitions, $name)) {
                    $definitions->{$name} = $definition;
                    $rewritten[] = $name;
                }
            }
        }

        if ([] === $removed && [] === $rewritten) {
            return $none;
        }

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $encoded) {
            return $none;
        }

        file_put_contents($composerManifestPath, $this->reindent($encoded, $raw) . PHP_EOL);

        return ['packages' => $removed, 'scripts' => $rewritten];
    }

    /**
     * Re-apply the original indentation (tabs, 2 or 4 spaces) so the rewrite
     * only shows the lines that actually changed. json_encode() always
     * indents with 4 spaces; only leading whitespace is converted.
     */
    private function reindent(string $encoded, string $original): string
    {
        if (1 !== preg_match('/^([ \t]+)"/m', $original, $match) || '    ' === $match[1]) {
            return $encoded;
        }

        $unit = $match[1];

        return (string) preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $spaces): string => str_repeat($unit, intdiv(\strlen($spaces[0]), 4)),
            $encoded,
        );
    }

    /** @param list<string> $relativePaths */
    private function removePaths(string $projectRoot, array $relativePaths): int
    {
        $removed = 0;

        foreach ($relativePaths as $relative) {
            $fullPath = $projectRoot . DIRECTORY_SEPARATOR . $relative;

            if (is_dir($fullPath) && ! is_link($fullPath)) {
                $this->removeRecursive($fullPath);
                ++$removed;
            } elseif (is_file($fullPath) || is_link($fullPath)) {
                unlink($fullPath);
                ++$removed;
            }
        }

        return $removed;
    }

    private function removeRecursive(string $directoryPath): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directoryPath);
    }
}
