<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use KaririCode\Devkit\Core\RuntimeOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeOptions::class)]
final class RuntimeOptionsTest extends TestCase
{
    #[Test]
    public function withoutFlagsTheProjectDefaultApplies(): void
    {
        $options = RuntimeOptions::parse(['kcode', 'test', '--coverage']);

        $this->assertSame(['kcode', 'test', '--coverage'], $options->arguments);
        $this->assertNull($options->docker);
        $this->assertTrue($options->useDocker(true));
        $this->assertFalse($options->useDocker(false));
    }

    #[Test]
    public function dockerFlagIsStrippedFromAnyPosition(): void
    {
        $options = RuntimeOptions::parse(['kcode', '--docker', 'test', '--suite=Unit']);

        $this->assertSame(['kcode', 'test', '--suite=Unit'], $options->arguments);
        $this->assertTrue($options->useDocker(false));
    }

    #[Test]
    public function noDockerFlagOverridesTheProjectDefault(): void
    {
        $options = RuntimeOptions::parse(['kcode', 'quality', '--no-docker']);

        $this->assertSame(['kcode', 'quality'], $options->arguments);
        $this->assertFalse($options->useDocker(true));
    }

    #[Test]
    public function flagsAfterTheEndOfOptionsMarkerAreKept(): void
    {
        $options = RuntimeOptions::parse(['kcode', 'exec', '--', 'php', 'script.php', '--docker']);

        $this->assertSame(['kcode', 'exec', '--', 'php', 'script.php', '--docker'], $options->arguments);
        $this->assertNull($options->docker);
    }

    #[Test]
    public function theScriptNameIsNeverTreatedAsAFlag(): void
    {
        $options = RuntimeOptions::parse(['--docker']);

        $this->assertSame(['--docker'], $options->arguments);
        $this->assertNull($options->docker);
    }

    /** @return iterable<string, array{string, bool|null}> */
    public static function environmentValues(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
        yield 'on uppercase' => [' ON ', true];
        yield 'zero' => ['0', false];
        yield 'no' => ['no', false];
        yield 'unrecognized' => ['maybe', null];
    }

    #[Test]
    #[DataProvider('environmentValues')]
    public function environmentVariableSetsTheMode(string $value, ?bool $expected): void
    {
        $options = RuntimeOptions::parse(['kcode', 'test'], ['KCODE_DOCKER' => $value]);

        $this->assertSame($expected, $options->docker);
    }

    #[Test]
    public function commandLineFlagWinsOverTheEnvironment(): void
    {
        $options = RuntimeOptions::parse(['kcode', 'test', '--no-docker'], ['KCODE_DOCKER' => '1']);

        $this->assertFalse($options->docker);
    }
}
