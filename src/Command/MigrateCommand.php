<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Command;

use const DIRECTORY_SEPARATOR;

use KaririCode\Devkit\Core\Devkit;
use KaririCode\Devkit\Core\MigrationDetector;
use KaririCode\Devkit\Core\MigrationService;
use KaririCode\Devkit\ValueObject\MigrationReport;
use Override;

/**
 * Moves a component onto the kcode toolchain and container runtime.
 *
 * Detects, displays and — after confirmation — removes:
 *   - per-component Docker scaffolding (`.docker/`, compose files, the
 *     legacy `.env.example` and Makefile), replaced by `kcode up`;
 *   - QA tools in `require-dev`, now installed into `.kcode/vendor/`;
 *   - test-support libraries in `require-dev`, moved to devkit.php → tools;
 *   - root tool configs (skip with `--keep-configs`) and cache paths;
 * and rewrites Composer scripts that called the removed binaries.
 *
 * Flags: `--dry-run`/`--check`, `-n`/`--no-interaction`, `--keep-configs`.
 * Safe to run multiple times — only acts on items that still exist.
 *
 * @since 1.0.0
 */
final class MigrateCommand extends AbstractCommand
{
    public function __construct(
        private readonly MigrationDetector $detector,
        private readonly MigrationService $service = new MigrationService(),
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'migrate';
    }

    #[Override]
    public function description(): string
    {
        return 'Remove Docker scaffolding, QA tools in require-dev and root configs (--keep-configs)';
    }

    #[Override]
    public function execute(Devkit $devkit, array $arguments): int
    {
        $this->banner('KaririCode Devkit — Migrate');

        $dryRun = $this->hasFlag($arguments, '--dry-run', '--check');
        $noInteraction = $this->hasFlag($arguments, '--no-interaction', '-n');
        $keepConfigs = $this->hasFlag($arguments, '--keep-configs');

        $context = $devkit->context();
        $report = $this->detector->detect($context->projectRoot, $keepConfigs);

        // --keep-configs opts the project into its root configs (devkit.php → root_configs).
        $keptConfigs = $keepConfigs ? $this->detector->rootToolConfigs($context->projectRoot) : [];
        $enableRootConfigs = [] !== $keptConfigs && ! $context->rootConfigs;

        if (! $report->hasRedundancies && ! $enableRootConfigs) {
            $this->info('Nothing to migrate: no Docker scaffolding, QA tools or redundant configs found.');

            return 0;
        }

        $this->renderReport($report, $keepConfigs);

        if ($enableRootConfigs) {
            $this->section("Root tool configs kept — devkit.php → 'root_configs' => true");

            foreach ($keptConfigs as $file) {
                $this->line("    \033[32m✓\033[0m {$file}");
            }

            $this->line();
        }

        if ($dryRun) {
            $this->warning('Dry-run mode — no changes applied.');

            return 0;
        }

        $removedPaths = 0;

        // ── Docker scaffolding ────────────────────────────────────
        if ($report->hasDockerPaths()) {
            if ($noInteraction || $this->confirm('Remove the Docker scaffolding? (kcode up replaces it)')) {
                $removed = $this->service->removeDockerScaffolding($report);
                $removedPaths += $removed;
                $this->info("Removed {$removed} Docker file(s)/directory(ies).");
            } else {
                $this->warning('Kept the Docker scaffolding.');
            }
        }

        // ── Config files & caches ─────────────────────────────────
        if ($report->hasConfigFiles() || $report->hasCachePaths()) {
            if ($noInteraction || $this->confirm('Remove these config files and cache paths?')) {
                $removed = $this->service->removeFiles($report);
                $removedPaths += $removed;
                $this->info("Removed {$removed} file(s)/directory(ies).");
            } else {
                $this->warning('Skipped file removal.');
            }
        }

        // ── composer.json ─────────────────────────────────────────
        $manifest = ['packages' => [], 'scripts' => [], 'relocated' => true];

        if ($report->changesComposerManifest()) {
            if ($noInteraction || $this->confirm('Update composer.json (require-dev and scripts)?')) {
                $manifest = $this->service->updateComposerManifest($report);
                $this->renderManifestChanges($report, $manifest);
            } else {
                $this->warning('Skipped composer.json modification.');
            }
        }

        // ── devkit.php root_configs ───────────────────────────────
        $rootConfigsEnabled = false;

        if ($enableRootConfigs) {
            if ($noInteraction || $this->confirm("Set 'root_configs' => true in devkit.php so kcode uses the kept configs?")) {
                $rootConfigsEnabled = $this->service->enableRootConfigs($report);

                $rootConfigsEnabled
                    ? $this->info("devkit.php: 'root_configs' => true — kcode runs the tools with the root configs.")
                    : $this->warning("Could not update devkit.php — add 'root_configs' => true yourself.");
            } else {
                $this->warning('Root configs kept but not enabled: kcode keeps using the generated .kcode/ configs.');
            }
        }

        // ── Summary ──────────────────────────────────────────────
        $this->section('Summary');

        $totalActioned = $removedPaths
            + \count($manifest['packages'])
            + \count($manifest['scripts'])
            + ($rootConfigsEnabled ? 1 : 0);

        if (0 === $totalActioned) {
            $this->warning('No changes applied.');

            return 0;
        }

        $this->info("{$totalActioned} item(s) migrated.");
        $this->line();
        $this->line('  Next steps:');

        if ([] !== $manifest['packages']) {
            $this->line("    \033[1mcomposer update\033[0m   drop the removed packages from composer.lock and vendor/");
        }

        $this->line("    \033[1mkcode quality\033[0m     provisions .kcode/ and runs the full pipeline");

        if ($report->hasDockerPaths()) {
            $this->line("    \033[1mkcode up\033[0m          starts the shared PHP container (.kcode/docker/)");
        }

        return 0;
    }

    private function renderReport(MigrationReport $report, bool $keepConfigs): void
    {
        $this->line(\sprintf(
            "  Found \033[1m%d\033[0m item(s) that kcode replaces:",
            $report->totalItems,
        ));

        if ($report->hasDockerPaths()) {
            $this->section('Docker scaffolding (replaced by kcode up / .kcode/docker/)');

            foreach ($report->legacyDockerPaths as $path) {
                $suffix = is_dir($report->projectRoot . DIRECTORY_SEPARATOR . $path) ? '/' : '';
                $this->line("    \033[31m✗\033[0m {$path}{$suffix}");
            }
        }

        if ($report->hasPackages()) {
            $this->section('composer.json require-dev (installed into .kcode/vendor/ or superseded)');

            foreach ($report->redundantPackages as $package => $version) {
                $this->line("    \033[31m✗\033[0m {$package}: {$version}");
            }
        }

        if ($report->hasRelocatedPackages()) {
            $this->section("composer.json require-dev → devkit.php 'tools'");

            foreach ($report->relocatedPackages as $package => $version) {
                $this->line("    \033[33m→\033[0m {$package}: {$version}");
            }
        }

        if ($report->hasConfigFiles()) {
            $this->section($keepConfigs ? 'Superseded tool configs' : 'Root-level config files');

            foreach ($report->redundantConfigFiles as $file) {
                $this->line("    \033[31m✗\033[0m {$file}");
            }
        }

        if ($report->hasCachePaths()) {
            $this->section('Root-level cache paths');

            foreach ($report->redundantCachePaths as $cachePath) {
                $isDir = is_dir($report->projectRoot . DIRECTORY_SEPARATOR . $cachePath);
                $suffix = $isDir ? '/' : '';
                $this->line("    \033[31m✗\033[0m {$cachePath}{$suffix}");
            }
        }

        if ($report->hasScriptRewrites()) {
            $this->section('composer.json scripts → kcode');

            foreach ($report->scriptRewrites as $name => $rewrite) {
                $this->line(\sprintf(
                    "    \033[33m~\033[0m %s: %s \033[2m→\033[0m %s",
                    $name,
                    $this->describe($rewrite['from']),
                    $this->describe($rewrite['to']),
                ));
            }
        }

        $this->line();
    }

    /** @param array{packages: list<string>, scripts: list<string>, relocated: bool} $manifest */
    private function renderManifestChanges(MigrationReport $report, array $manifest): void
    {
        if ([] !== $manifest['packages']) {
            $this->info(\sprintf(
                'Removed %d package(s) from require-dev: %s',
                \count($manifest['packages']),
                implode(', ', $manifest['packages']),
            ));
        }

        if ($report->hasRelocatedPackages()) {
            if ($manifest['relocated']) {
                $this->info("Listed in devkit.php 'tools': " . implode(', ', array_keys($report->relocatedPackages)));
            } else {
                $this->warning("devkit.php already defines 'tools' — add these and remove them from require-dev yourself:");

                foreach ($report->relocatedPackages as $package => $version) {
                    $this->line("    '{$package}' => '{$version}',");
                }
            }
        }

        if ([] !== $manifest['scripts']) {
            $this->info('Rewrote composer script(s): ' . implode(', ', $manifest['scripts']));
        }
    }

    /** @param string|list<string> $definition */
    private function describe(string|array $definition): string
    {
        return \is_string($definition) ? $definition : '[' . implode(', ', $definition) . ']';
    }
}
