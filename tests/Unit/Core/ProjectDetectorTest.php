<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use FilesystemIterator;
use KaririCode\Devkit\Core\DevkitConfig;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\Core\ProjectDetector;
use KaririCode\Devkit\Exception\DevkitException;
use KaririCode\Devkit\ValueObject\DockerSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectDetector::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(DevkitConfig::class)]
#[UsesClass(DockerSettings::class)]
final class ProjectDetectorTest extends TestCase
{
    private string $tmpDir;
    private ProjectDetector $detector;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/devkit_detector_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
        $this->detector = new ProjectDetector();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function detectThrowsWhenComposerJsonMissing(): void
    {
        $this->expectException(DevkitException::class);
        $this->detector->detect($this->tmpDir);
    }

    #[Test]
    public function detectReturnsProjectContext(): void
    {
        $this->writeComposer([
            'name' => 'vendor/project',
            'autoload' => ['psr-4' => ['Vendor\\Project\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Vendor\\Project\\Tests\\' => 'tests/']],
        ]);
        mkdir($this->tmpDir . '/src', 0o777, true);

        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertInstanceOf(ProjectContext::class, $ctx);
    }

    #[Test]
    public function detectParsesProjectName(): void
    {
        $this->writeComposer(['name' => 'acme/my-lib']);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertSame('acme/my-lib', $ctx->projectName);
    }

    #[Test]
    public function detectFallsBackToDirectoryNameWhenNoComposerName(): void
    {
        $this->writeComposer([]);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertSame(basename($this->tmpDir), $ctx->projectName);
    }

    #[Test]
    public function detectParsesNamespaceFromPsr4(): void
    {
        $this->writeComposer([
            'name' => 'vendor/project',
            'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']],
        ]);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertStringContainsString('Acme', $ctx->namespace);
    }

    #[Test]
    public function detectUsesDefaultPhpVersionWhenMissing(): void
    {
        $this->writeComposer(['name' => 'test/pkg']);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertNotEmpty($ctx->phpVersion);
    }

    #[Test]
    public function detectParsesPhpVersionFromRequire(): void
    {
        $this->writeComposer([
            'name' => 'test/pkg',
            'require' => ['php' => '>=8.3'],
        ]);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertStringContainsString('8.3', $ctx->phpVersion);
    }

    #[Test]
    public function detectSetsDefaultSourceDirWhenSrcNotInPsr4(): void
    {
        mkdir($this->tmpDir . '/src', 0o777, true);
        $this->writeComposer(['name' => 'test/pkg']);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertNotEmpty($ctx->sourceDirs);
    }

    #[Test]
    public function detectSetsDefaultTestDirWhenTestsNotInPsr4(): void
    {
        mkdir($this->tmpDir . '/tests', 0o777, true);
        $this->writeComposer(['name' => 'test/pkg']);
        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertNotEmpty($ctx->testDirs);
    }

    #[Test]
    public function detectLoadsDevkitConfigOverrides(): void
    {
        $this->writeComposer(['name' => 'test/pkg']);
        file_put_contents($this->tmpDir . '/devkit.php', "<?php return ['phpstan_level' => 7];");

        $ctx = $this->detector->detect($this->tmpDir);
        $this->assertSame(7, $ctx->phpstanLevel);
    }

    #[Test]
    public function detectFlagsKaririCodePackagesAsComponents(): void
    {
        $this->writeComposer(['name' => 'kariricode/validator']);

        $this->assertTrue($this->detector->detect($this->tmpDir)->kaririCodeComponent);
    }

    #[Test]
    public function detectDoesNotFlagThirdPartyPackages(): void
    {
        $this->writeComposer(['name' => 'acme/library']);

        $this->assertFalse($this->detector->detect($this->tmpDir)->kaririCodeComponent);
    }

    #[Test]
    public function detectTreatsDevkitConfigAsAnOptIn(): void
    {
        $this->writeComposer(['name' => 'acme/library']);
        file_put_contents($this->tmpDir . '/devkit.php', '<?php return [];');

        $this->assertTrue($this->detector->detect($this->tmpDir)->kaririCodeComponent);
    }

    #[Test]
    public function detectDefaultsDockerPhpVersionToTheProjectPhpVersion(): void
    {
        $this->writeComposer(['name' => 'kariricode/validator', 'require' => ['php' => '^8.5']]);

        $docker = $this->detector->detect($this->tmpDir)->docker;

        $this->assertSame('8.5', $docker->phpVersion);
        $this->assertFalse($docker->enabled);
    }

    #[Test]
    public function detectKeepsRootConfigsOffByDefault(): void
    {
        $this->writeComposer(['name' => 'kariricode/normalizer']);
        file_put_contents($this->tmpDir . '/.php-cs-fixer.php', '<?php return [];');

        $this->assertFalse($this->detector->detect($this->tmpDir)->rootConfigs);
    }

    #[Test]
    public function detectReadsTheRootConfigsOptIn(): void
    {
        $this->writeComposer(['name' => 'kariricode/property-inspector']);
        file_put_contents($this->tmpDir . '/devkit.php', "<?php return ['root_configs' => true];");

        $this->assertTrue($this->detector->detect($this->tmpDir)->rootConfigs);
    }

    #[Test]
    public function detectLoadsDockerOverrides(): void
    {
        $this->writeComposer(['name' => 'kariricode/cache']);
        file_put_contents(
            $this->tmpDir . '/devkit.php',
            "<?php return ['docker' => ['enabled' => true, 'services' => ['redis' => 'redis:7-alpine']]];",
        );

        $docker = $this->detector->detect($this->tmpDir)->docker;

        $this->assertTrue($docker->enabled);
        $this->assertSame(['redis' => 'redis:7-alpine'], $docker->services);
    }

    /** @param array<mixed> $data */
    private function writeComposer(array $data): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT),
        );
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
