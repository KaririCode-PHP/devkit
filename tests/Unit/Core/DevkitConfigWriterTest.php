<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use KaririCode\Devkit\Core\DevkitConfig;
use KaririCode\Devkit\Core\DevkitConfigWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DevkitConfigWriter::class)]
#[UsesClass(DevkitConfig::class)]
final class DevkitConfigWriterTest extends TestCase
{
    private string $root;
    private DevkitConfigWriter $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devkit_writer_test_' . uniqid();
        mkdir($this->root, 0o777, true);
        $this->writer = new DevkitConfigWriter();
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/devkit.php');
        rmdir($this->root);
    }

    #[Test]
    public function writesAMinimalFileWhenNoneExists(): void
    {
        $this->assertTrue($this->writer->addTools($this->root, ['mockery/mockery' => '^1.6', 'infection/infection' => '^0.29']));

        $config = new DevkitConfig($this->root);

        $this->assertSame(['infection/infection' => '^0.29', 'mockery/mockery' => '^1.6'], $config->toolVersions());
        $this->assertStringStartsWith("<?php\n\ndeclare(strict_types=1);", (string) file_get_contents($this->root . '/devkit.php'));
    }

    #[Test]
    public function insertsToolsIntoAnExistingFileWithoutThem(): void
    {
        file_put_contents($this->root . '/devkit.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'phpstan_level' => 8,
                // 'tools' => [],
            ];

            PHP);

        $this->assertTrue($this->writer->addTools($this->root, ['mockery/mockery' => '^1.6']));

        $config = new DevkitConfig($this->root);

        $this->assertSame(8, $config->get('phpstan_level', 9));
        $this->assertSame(['mockery/mockery' => '^1.6'], $config->toolVersions());
        $this->assertStringContainsString("// 'tools' => [],", (string) file_get_contents($this->root . '/devkit.php'));
    }

    #[Test]
    public function acceptsAnExistingToolsKeyThatAlreadyListsThePackages(): void
    {
        $original = "<?php\n\nreturn ['tools' => ['mockery/mockery' => '^1.6']];\n";
        file_put_contents($this->root . '/devkit.php', $original);

        $this->assertTrue($this->writer->addTools($this->root, ['mockery/mockery' => '^1.5']));
        $this->assertSame($original, file_get_contents($this->root . '/devkit.php'));
    }

    #[Test]
    public function refusesToRewriteAnExistingToolsKey(): void
    {
        $original = "<?php\n\nreturn ['tools' => ['phpunit' => '^12.5']];\n";
        file_put_contents($this->root . '/devkit.php', $original);

        $this->assertFalse($this->writer->addTools($this->root, ['mockery/mockery' => '^1.6']));
        $this->assertSame($original, file_get_contents($this->root . '/devkit.php'));
    }

    #[Test]
    public function refusesFilesItCannotParseOrPatch(): void
    {
        file_put_contents($this->root . '/devkit.php', "<?php\n\nreturn 'not an array';\n");
        $this->assertFalse($this->writer->addTools($this->root, ['mockery/mockery' => '^1.6']));

        file_put_contents($this->root . '/devkit.php', "<?php\n\nreturn array('phpstan_level' => 8);\n");
        $this->assertFalse($this->writer->addTools($this->root, ['mockery/mockery' => '^1.6']));
    }

    #[Test]
    public function nothingToAddIsASuccess(): void
    {
        $this->assertTrue($this->writer->addTools($this->root, []));
        $this->assertFileDoesNotExist($this->root . '/devkit.php');
    }
}
