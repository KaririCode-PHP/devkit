<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use FilesystemIterator;
use KaririCode\Devkit\Core\ProjectLocator;
use KaririCode\Devkit\Exception\DevkitException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectLocator::class)]
#[UsesClass(DevkitException::class)]
final class ProjectLocatorTest extends TestCase
{
    private string $projectRoot;
    private ProjectLocator $locator;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/devkit_locator_test_' . uniqid();
        mkdir($this->projectRoot . '/src/Rule/Nested', 0o777, true);
        mkdir($this->projectRoot . '/.kcode/vendor/phpunit/phpunit', 0o777, true);
        file_put_contents($this->projectRoot . '/composer.json', '{"name": "kariricode/validator"}');
        file_put_contents($this->projectRoot . '/.kcode/composer.json', '{"name": "kariricode/devkit-tools"}');
        file_put_contents($this->projectRoot . '/.kcode/vendor/phpunit/phpunit/composer.json', '{}');

        $this->projectRoot = (string) realpath($this->projectRoot);
        $this->locator = new ProjectLocator();
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->projectRoot);
    }

    #[Test]
    public function locateReturnsTheDirectoryHoldingComposerJson(): void
    {
        $this->assertSame($this->projectRoot, $this->locator->locate($this->projectRoot));
    }

    #[Test]
    public function locateWalksUpFromANestedSubdirectory(): void
    {
        $this->assertSame($this->projectRoot, $this->locator->locate($this->projectRoot . '/src/Rule/Nested'));
    }

    #[Test]
    public function locateSkipsTheGeneratedToolchainManifest(): void
    {
        $this->assertSame($this->projectRoot, $this->locator->locate($this->projectRoot . '/.kcode'));
    }

    #[Test]
    public function locateSkipsPackagesInstalledInsideKcode(): void
    {
        $nested = $this->projectRoot . '/.kcode/vendor/phpunit/phpunit';

        $this->assertSame($this->projectRoot, $this->locator->locate($nested));
    }

    #[Test]
    public function locateThrowsWhenNoComposerJsonExistsUpToTheRoot(): void
    {
        $this->expectException(DevkitException::class);
        $this->expectExceptionMessage('No composer.json found');

        $this->locator->locate('/');
    }
}
