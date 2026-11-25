<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use FilesystemIterator;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use KaririCode\Devkit\Core\DevkitConfig;
use KaririCode\Devkit\Core\DevkitConfigWriter;
use KaririCode\Devkit\Core\MigrationService;
use KaririCode\Devkit\ValueObject\MigrationReport;

use const PHP_EOL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(MigrationService::class)]
#[UsesClass(MigrationReport::class)]
#[UsesClass(DevkitConfigWriter::class)]
#[UsesClass(DevkitConfig::class)]
final class MigrationServiceTest extends TestCase
{
    private MigrationService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = new MigrationService();
        $this->tmpDir = sys_get_temp_dir() . '/migration_service_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeRecursive($this->tmpDir);
        }
    }

    #[Test]
    public function removeFilesDeletesExistingFile(): void
    {
        $configFile = $this->tmpDir . '/phpstan.neon';
        file_put_contents($configFile, 'parameters:');

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: [],
            redundantConfigFiles: ['phpstan.neon'],
            redundantCachePaths: [],
        );

        $removed = $this->service->removeFiles($report);

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($configFile);
    }

    #[Test]
    public function removeFilesDeletesDirectory(): void
    {
        $cacheDir = $this->tmpDir . '/.phpunit.cache';
        mkdir($cacheDir, 0o777, true);
        file_put_contents($cacheDir . '/results', 'cached');

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: [],
            redundantConfigFiles: [],
            redundantCachePaths: ['.phpunit.cache'],
        );

        $removed = $this->service->removeFiles($report);

        $this->assertSame(1, $removed);
        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    #[Test]
    public function removeFilesSkipsNonExistentPaths(): void
    {
        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: [],
            redundantConfigFiles: ['non-existent.neon'],
            redundantCachePaths: [],
        );

        $removed = $this->service->removeFiles($report);

        $this->assertSame(0, $removed);
    }

    #[Test]
    public function removePackagesFromComposerRemovesPackage(): void
    {
        $composerContent = json_encode([
            'name' => 'test/project',
            'require-dev' => [
                'phpunit/phpunit' => '^11.0',
                'phpstan/phpstan' => '^2.0',
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($this->tmpDir . '/composer.json', $composerContent . PHP_EOL);

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: ['phpstan/phpstan' => '^2.0'],
            redundantConfigFiles: [],
            redundantCachePaths: [],
        );

        $removed = $this->service->removePackagesFromComposer($report);

        $this->assertSame(['phpstan/phpstan'], $removed);

        /** @var array<string, mixed> $updated */
        $updated = json_decode(
            (string) file_get_contents($this->tmpDir . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('phpunit/phpunit', $updated['require-dev']);
        $this->assertArrayNotHasKey('phpstan/phpstan', $updated['require-dev']);
    }

    #[Test]
    public function removePackagesFromComposerReturnsEmptyIfNoComposerJson(): void
    {
        $report = new MigrationReport(
            projectRoot: $this->tmpDir . '/non-existent',
            redundantPackages: ['some/package' => '^1.0'],
            redundantConfigFiles: [],
            redundantCachePaths: [],
        );

        $removed = $this->service->removePackagesFromComposer($report);

        $this->assertSame([], $removed);
    }

    // ── Since 1.2.0 ───────────────────────────────────────────────

    #[Test]
    public function removeDockerScaffoldingDeletesFilesAndDirectories(): void
    {
        mkdir($this->tmpDir . '/.docker/php', 0o777, true);
        file_put_contents($this->tmpDir . '/.docker/php/Dockerfile', 'FROM php');
        file_put_contents($this->tmpDir . '/docker-compose.yml', 'services: {}');

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: [],
            redundantConfigFiles: [],
            redundantCachePaths: [],
            legacyDockerPaths: ['.docker', 'docker-compose.yml', 'Makefile'],
        );

        $this->assertSame(2, $this->service->removeDockerScaffolding($report));
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/.docker');
        $this->assertFileDoesNotExist($this->tmpDir . '/docker-compose.yml');
    }

    #[Test]
    public function updateComposerManifestAppliesPackagesRelocationAndScripts(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', <<<'JSON'
            {
                "name": "kariricode/logging",
                "require": {
                    "php": "^8.4"
                },
                "require-dev": {
                    "phpunit/phpunit": "^10.5",
                    "mockery/mockery": "^1.6"
                },
                "scripts": {
                    "test": "phpunit",
                    "sbom": "@php tools/sbom.php"
                },
                "extra": {}
            }

            JSON);

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: ['phpunit/phpunit' => '^10.5'],
            redundantConfigFiles: [],
            redundantCachePaths: [],
            relocatedPackages: ['mockery/mockery' => '^1.6'],
            scriptRewrites: ['test' => ['from' => 'phpunit', 'to' => 'kcode test']],
        );

        $result = $this->service->updateComposerManifest($report);

        $this->assertSame(['phpunit/phpunit', 'mockery/mockery'], $result['packages']);
        $this->assertSame(['test'], $result['scripts']);
        $this->assertTrue($result['relocated']);

        $updated = (string) file_get_contents($this->tmpDir . '/composer.json');

        $this->assertStringContainsString('"require-dev": {}', $updated, 'Empty objects stay objects.');
        $this->assertStringContainsString('"extra": {}', $updated);
        $this->assertStringContainsString('"test": "kcode test"', $updated);
        $this->assertStringContainsString('"sbom": "@php tools/sbom.php"', $updated);
        $this->assertSame(['mockery/mockery' => '^1.6'], new DevkitConfig($this->tmpDir)->toolVersions());

        unlink($this->tmpDir . '/devkit.php');
    }

    #[Test]
    public function updateComposerManifestKeepsRelocatedPackagesWhenDevkitConfigCannotBeUpdated(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{"name": "kariricode/logging", "require-dev": {"mockery/mockery": "^1.6"}}');
        file_put_contents($this->tmpDir . '/devkit.php', "<?php\n\nreturn ['tools' => ['phpunit' => '^12.5']];\n");

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: [],
            redundantConfigFiles: [],
            redundantCachePaths: [],
            relocatedPackages: ['mockery/mockery' => '^1.6'],
        );

        $result = $this->service->updateComposerManifest($report);

        $this->assertFalse($result['relocated']);
        $this->assertSame([], $result['packages']);
        $this->assertStringContainsString('mockery/mockery', (string) file_get_contents($this->tmpDir . '/composer.json'));

        unlink($this->tmpDir . '/devkit.php');
    }

    #[Test]
    public function removePackagesFromComposerPreservesTabIndentation(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', "{\n\t\"name\": \"test/project\",\n\t\"require-dev\": {\n\t\t\"vimeo/psalm\": \"^6.0\"\n\t}\n}\n");

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: ['vimeo/psalm' => '^6.0'],
            redundantConfigFiles: [],
            redundantCachePaths: [],
        );

        $this->assertSame(['vimeo/psalm'], $this->service->removePackagesFromComposer($report));
        $this->assertSame("{\n\t\"name\": \"test/project\",\n\t\"require-dev\": {}\n}\n", file_get_contents($this->tmpDir . '/composer.json'));
    }

    #[Test]
    public function rewritesKeepTwoSpaceIndentationAndStringContent(): void
    {
        $original = "{\n  \"name\": \"kariricode/validator\",\n  \"description\": \"keeps    four spaces\",\n"
            . "  \"require-dev\": {\n    \"phpunit/phpunit\": \"^11.0\",\n    \"kariricode/contract\": \"^2.7\"\n  }\n}\n";
        file_put_contents($this->tmpDir . '/composer.json', $original);

        $report = new MigrationReport(
            projectRoot: $this->tmpDir,
            redundantPackages: ['phpunit/phpunit' => '^11.0'],
            redundantConfigFiles: [],
            redundantCachePaths: [],
        );

        $this->service->removePackagesFromComposer($report);

        $this->assertSame(
            "{\n  \"name\": \"kariricode/validator\",\n  \"description\": \"keeps    four spaces\",\n"
            . "  \"require-dev\": {\n    \"kariricode/contract\": \"^2.7\"\n  }\n}\n",
            file_get_contents($this->tmpDir . '/composer.json'),
        );
    }

    private function removeRecursive(string $directoryPath): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directoryPath);
    }
}
