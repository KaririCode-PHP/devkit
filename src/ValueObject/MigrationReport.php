<?php

declare(strict_types=1);

namespace KaririCode\Devkit\ValueObject;

/**
 * Immutable snapshot of redundant items detected in a project
 * that the devkit replaces.
 *
 * Pure Value Object — no I/O side-effects. To apply the migration,
 * pass this report to {@see \KaririCode\Devkit\Core\MigrationService}.
 *
 * @since 1.0.0
 */
final readonly class MigrationReport
{
    public bool $hasRedundancies;
    public int $totalItems;

    /**
     * @param array<string, string>                                                  $redundantPackages    Removed from require-dev: package → constraint
     * @param list<string>                                                           $redundantConfigFiles Filenames relative to project root
     * @param list<string>                                                           $redundantCachePaths  Cache paths relative to project root
     * @param list<string>                                                           $legacyDockerPaths    Per-component Docker scaffolding (since 1.2.0)
     * @param array<string, string>                                                  $relocatedPackages    Moved to devkit.php → tools: package → constraint (since 1.2.0)
     * @param array<string, array{from: string|list<string>, to: string|list<string>}> $scriptRewrites       Composer scripts rewritten to kcode (since 1.2.0)
     */
    public function __construct(
        public string $projectRoot,
        public array $redundantPackages,
        public array $redundantConfigFiles,
        public array $redundantCachePaths,
        public array $legacyDockerPaths = [],
        public array $relocatedPackages = [],
        public array $scriptRewrites = [],
    ) {
        $this->totalItems = \count($redundantPackages)
            + \count($redundantConfigFiles)
            + \count($redundantCachePaths)
            + \count($legacyDockerPaths)
            + \count($relocatedPackages)
            + \count($scriptRewrites);
        $this->hasRedundancies = $this->totalItems > 0;
    }

    public function hasPackages(): bool
    {
        return [] !== $this->redundantPackages;
    }

    public function hasConfigFiles(): bool
    {
        return [] !== $this->redundantConfigFiles;
    }

    public function hasCachePaths(): bool
    {
        return [] !== $this->redundantCachePaths;
    }

    /** @since 1.2.0 */
    public function hasDockerPaths(): bool
    {
        return [] !== $this->legacyDockerPaths;
    }

    /** @since 1.2.0 */
    public function hasRelocatedPackages(): bool
    {
        return [] !== $this->relocatedPackages;
    }

    /** @since 1.2.0 */
    public function hasScriptRewrites(): bool
    {
        return [] !== $this->scriptRewrites;
    }

    /**
     * Whether composer.json changes (packages or scripts).
     *
     * @since 1.2.0
     */
    public function changesComposerManifest(): bool
    {
        return $this->hasPackages() || $this->hasRelocatedPackages() || $this->hasScriptRewrites();
    }
}
