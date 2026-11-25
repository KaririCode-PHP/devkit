<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Configuration;

use KaririCode\Devkit\Configuration\YamlWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlWriter::class)]
final class YamlWriterTest extends TestCase
{
    private YamlWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new YamlWriter();
    }

    #[Test]
    public function dumpRendersNestedMapsAndLists(): void
    {
        $yaml = $this->writer->dump([
            'services' => [
                'php' => [
                    'init' => true,
                    'command' => ['sleep', 'infinity'],
                ],
            ],
        ]);

        $expected = <<<'YAML'
            services:
              php:
                init: true
                command:
                  - "sleep"
                  - "infinity"

            YAML;

        $this->assertSame($expected, $yaml);
    }

    #[Test]
    public function dumpQuotesStringsSoTheyAreNeverReinterpreted(): void
    {
        $yaml = $this->writer->dump(['version' => '8.4', 'flag' => 'yes', 'port' => '9003:9003']);

        $this->assertStringContainsString('version: "8.4"', $yaml);
        $this->assertStringContainsString('flag: "yes"', $yaml);
        $this->assertStringContainsString('port: "9003:9003"', $yaml);
    }

    #[Test]
    public function dumpRendersScalarsByType(): void
    {
        $yaml = $this->writer->dump(['int' => 3, 'float' => 1.5, 'off' => false, 'none' => null, 'empty' => []]);

        $this->assertSame("int: 3\nfloat: 1.5\noff: false\nnone: null\nempty: []\n", $yaml);
    }

    #[Test]
    public function dumpQuotesKeysWithSpecialCharacters(): void
    {
        $yaml = $this->writer->dump(['host.docker.internal' => 'a', 'with space' => 'b']);

        $this->assertStringContainsString('host.docker.internal: "a"', $yaml);
        $this->assertStringContainsString('"with space": "b"', $yaml);
    }

    #[Test]
    public function dumpRendersMapsInsideLists(): void
    {
        $yaml = $this->writer->dump(['items' => [['name' => 'a'], 'b']]);

        $this->assertSame("items:\n  -\n    name: \"a\"\n  - \"b\"\n", $yaml);
    }
}
