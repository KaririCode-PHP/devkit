<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

use KaririCode\Devkit\ValueObject\MigrationReport;

/**
 * Detects what a component no longer needs once kcode manages its toolchain
 * and container.
 *
 * Categories:
 *   - require-dev QA tools kcode installs into `.kcode/vendor/`, and legacy
 *     ones it supersedes (PHP_CodeSniffer, PHP Insights, Security Checker…);
 *   - test-support libraries that move to devkit.php → `tools`, so they are
 *     installed into `.kcode/vendor/` instead of `vendor/`;
 *   - root tool configs (kept with `--keep-configs`) and configs of the
 *     superseded tools;
 *   - root cache paths consolidated under `.kcode/build/`;
 *   - per-component Docker scaffolding replaced by `.kcode/docker/`;
 *   - Composer scripts that call the removed binaries.
 *
 * @since 1.0.0
 */
final class MigrationDetector
{
    /** Composer packages that the kcode toolchain installs into `.kcode/vendor/`. */
    private const array REPLACED_PACKAGES = [
        'phpunit/phpunit',
        'phpstan/phpstan',
        'phpstan/extension-installer',
        'phpstan/phpstan-deprecation-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-strict-rules',
        'friendsofphp/php-cs-fixer',
        'rector/rector',
        'vimeo/psalm',
        'psalm/plugin-phpunit',
    ];

    /** Legacy QA packages superseded by the kcode pipeline (cs:fix, analyse, security). */
    private const array SUPERSEDED_PACKAGES = [
        'squizlabs/php_codesniffer',
        'nunomaduro/phpinsights',
        'enlightn/security-checker',
        'phpmd/phpmd',
        'sebastian/phpcpd',
        'symplify/easy-coding-standard',
    ];

    /** Test-support libraries that move to devkit.php → tools (installed into `.kcode/vendor/`). */
    private const array RELOCATED_PACKAGES = [
        'mockery/mockery',
        'infection/infection',
        'brianium/paratest',
        'phpspec/prophecy-phpunit',
        'dg/bypass-finals',
    ];

    /** Root-level config files that `.kcode/` generated configs replace. */
    private const array REPLACED_CONFIG_FILES = [
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpstan.neon',
        'phpstan.neon.dist',
        '.php-cs-fixer.php',
        '.php-cs-fixer.dist.php',
        'rector.php',
        'psalm.xml',
        'psalm.xml.dist',
    ];

    /** Configs of the superseded tools — removed even with `--keep-configs`. */
    private const array SUPERSEDED_CONFIG_FILES = [
        'phpcs.xml',
        'phpcs.xml.dist',
        '.phpcs.xml',
        '.phpcs.xml.dist',
        'phpinsights.php',
        'phpmd.xml',
        'phpmd.xml.dist',
        'ecs.php',
    ];

    /** Root-level cache artifacts that `.kcode/build/` consolidates. */
    private const array REPLACED_CACHE_PATHS = [
        '.phpunit.cache',
        '.phpunit.result.cache',
        '.phpstan',
        '.php-cs-fixer.cache',
        '.psalm',
        '.phpcs-cache',
        'coverage-report-html',
    ];

    /** Docker scaffolding replaced by `.kcode/docker/` — removed whenever present. */
    private const array DOCKER_PATHS = [
        '.docker',
        'docker',
        'docker-compose.yml',
        'docker-compose.yaml',
        'docker-compose.override.yml',
        'docker-compose.override.yaml',
        'compose.yml',
        'compose.yaml',
        'Dockerfile',
        '.dockerignore',
    ];

    /** Variables of the legacy `.env.example` template, which only configured docker-compose. */
    private const array LEGACY_ENV_VARIABLES = ['KARIRI_APP_ENV', 'KARIRI_PHP_VERSION', 'KARIRI_PHP_PORT'];

    public function __construct(
        private readonly ComposerScriptRewriter $scriptRewriter = new ComposerScriptRewriter(),
    ) {
    }

    /** @param bool $keepConfigs Keep root tool configs (KaririCode Spec V4.3.3 §31) */
    public function detect(string $projectRoot, bool $keepConfigs = false): MigrationReport
    {
        $composer = $this->readComposer($projectRoot);

        /** @var array<array-key, mixed> $requireDev */
        $requireDev = \is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        /** @var array<array-key, mixed> $scripts */
        $scripts = \is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

        $configFiles = $keepConfigs
            ? self::SUPERSEDED_CONFIG_FILES
            : [...self::REPLACED_CONFIG_FILES, ...self::SUPERSEDED_CONFIG_FILES];

        return new MigrationReport(
            projectRoot: $projectRoot,
            redundantPackages: $this->packages($requireDev, [...self::REPLACED_PACKAGES, ...self::SUPERSEDED_PACKAGES]),
            redundantConfigFiles: $this->existing($projectRoot, $configFiles, files: true),
            redundantCachePaths: $this->existing($projectRoot, self::REPLACED_CACHE_PATHS, files: false),
            legacyDockerPaths: $this->dockerPaths($projectRoot),
            relocatedPackages: $this->packages($requireDev, self::RELOCATED_PACKAGES),
            scriptRewrites: $this->scriptRewriter->rewrites($scripts),
        );
    }

    /**
     * Root tool configs present in the project (the files `--keep-configs` keeps).
     *
     * @return list<string>
     *
     * @since 1.2.1
     */
    public function rootToolConfigs(string $projectRoot): array
    {
        return $this->existing($projectRoot, self::REPLACED_CONFIG_FILES, files: true);
    }

    /** @return array<array-key, mixed> */
    private function readComposer(string $projectRoot): array
    {
        $composerPath = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';

        if (! is_file($composerPath)) {
            return [];
        }

        $raw = file_get_contents($composerPath);

        if (false === $raw) {
            return [];
        }

        $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($composer) ? $composer : [];
    }

    /**
     * @param array<array-key, mixed> $requireDev
     * @param list<string>            $candidates
     * @return array<string, string>
     */
    private function packages(array $requireDev, array $candidates): array
    {
        $found = [];

        foreach ($candidates as $package) {
            $constraint = $requireDev[$package] ?? null;

            if (\is_string($constraint)) {
                $found[$package] = $constraint;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function existing(string $projectRoot, array $paths, bool $files): array
    {
        return array_values(array_filter(
            $paths,
            static function (string $path) use ($projectRoot, $files): bool {
                $fullPath = $projectRoot . DIRECTORY_SEPARATOR . $path;

                return $files ? is_file($fullPath) : file_exists($fullPath);
            },
        ));
    }

    /** @return list<string> */
    private function dockerPaths(string $projectRoot): array
    {
        $paths = $this->existing($projectRoot, self::DOCKER_PATHS, files: false);

        if ($this->isLegacyEnvExample($projectRoot . DIRECTORY_SEPARATOR . '.env.example')) {
            $paths[] = '.env.example';
        }

        if ($this->isLegacyDockerMakefile($projectRoot . DIRECTORY_SEPARATOR . 'Makefile')) {
            $paths[] = 'Makefile';
        }

        return $paths;
    }

    /** True when every variable belongs to the legacy docker-compose template. */
    private function isLegacyEnvExample(string $path): bool
    {
        $lines = is_file($path) ? file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) : false;

        if (false === $lines) {
            return false;
        }

        $variables = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $variables[] = trim(explode('=', $line, 2)[0]);
        }

        return [] !== $variables
            && array_all($variables, static fn (string $name): bool => \in_array($name, self::LEGACY_ENV_VARIABLES, true));
    }

    /** True for the Makefile template that wrapped docker-compose (make up, make test, …). */
    private function isLegacyDockerMakefile(string $path): bool
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            return false;
        }

        $drivesCompose = str_contains($content, 'docker-compose') || str_contains($content, 'docker compose');

        return $drivesCompose && (str_contains($content, 'EXEC_PHP') || str_contains($content, 'vendor/bin/phpunit'));
    }
}
