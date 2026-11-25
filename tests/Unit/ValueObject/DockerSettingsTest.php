<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\ValueObject;

use KaririCode\Devkit\Exception\ConfigurationException;
use KaririCode\Devkit\ValueObject\DockerSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DockerSettings::class)]
#[UsesClass(ConfigurationException::class)]
final class DockerSettingsTest extends TestCase
{
    #[Test]
    public function fromEmptyArrayAppliesKaririCodeDefaults(): void
    {
        $settings = DockerSettings::fromArray([], '8.4');

        $this->assertFalse($settings->enabled);
        $this->assertSame('8.4', $settings->phpVersion);
        $this->assertNull($settings->image);
        $this->assertSame(DockerSettings::DEFAULT_EXTENSIONS, $settings->extensions);
        $this->assertSame([], $settings->packages);
        $this->assertSame([], $settings->ini);
        $this->assertSame([], $settings->environment);
        $this->assertSame([], $settings->ports);
        $this->assertSame([], $settings->services);
        $this->assertSame([], $settings->service);
        $this->assertTrue($settings->buildsImage());
    }

    #[Test]
    public function fromArrayReadsEveryKey(): void
    {
        $settings = DockerSettings::fromArray([
            'enabled' => true,
            'php_version' => '8.5',
            'image' => 'kariricode/php-api-stack:dev',
            'extensions' => ['pcov', 'redis'],
            'packages' => ['git'],
            'ini' => ['memory_limit' => '1G', 'opcache.enable_cli' => true, 'max_execution_time' => 60],
            'environment' => ['APP_ENV' => 'testing'],
            'ports' => ['8080:8080'],
            'services' => [
                'redis' => 'redis:7-alpine',
                'mysql' => ['image' => 'mysql:8.4', 'environment' => ['MYSQL_ROOT_PASSWORD' => 'root']],
            ],
            'service' => ['network_mode' => 'host'],
        ], '8.4');

        $this->assertTrue($settings->enabled);
        $this->assertSame('8.5', $settings->phpVersion);
        $this->assertSame('kariricode/php-api-stack:dev', $settings->image);
        $this->assertFalse($settings->buildsImage());
        $this->assertSame(['pcov', 'redis'], $settings->extensions);
        $this->assertSame(['git'], $settings->packages);
        $this->assertSame(['memory_limit' => '1G', 'opcache.enable_cli' => '1', 'max_execution_time' => '60'], $settings->ini);
        $this->assertSame(['APP_ENV' => 'testing'], $settings->environment);
        $this->assertSame(['8080:8080'], $settings->ports);
        $this->assertSame('redis:7-alpine', $settings->services['redis']);
        $this->assertSame(['image' => 'mysql:8.4', 'environment' => ['MYSQL_ROOT_PASSWORD' => 'root']], $settings->services['mysql']);
        $this->assertSame(['network_mode' => 'host'], $settings->service);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSettings(): iterable
    {
        yield 'enabled not boolean' => [['enabled' => 'yes'], 'docker.enabled'];
        yield 'empty image' => [['image' => ' '], 'docker.image'];
        yield 'php_version not string' => [['php_version' => 8.4], 'docker.php_version'];
        yield 'extensions not a list' => [['extensions' => ['pcov' => true]], 'docker.extensions'];
        yield 'extensions with non-string' => [['extensions' => ['pcov', 1]], 'docker.extensions'];
        yield 'ini is a list' => [['ini' => ['memory_limit=1G']], 'docker.ini'];
        yield 'ini value is an array' => [['ini' => ['memory_limit' => ['1G']]], 'docker.ini.memory_limit'];
        yield 'service definition is a list' => [['services' => ['redis' => ['redis:7']]], 'docker.services.redis'];
        yield 'service definition is a number' => [['services' => ['redis' => 7]], 'docker.services.redis'];
    }

    /** @param array<string, mixed> $settings */
    #[Test]
    #[DataProvider('invalidSettings')]
    public function fromArrayRejectsInvalidValues(array $settings, string $key): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(\sprintf('Invalid devkit.php key "%s"', $key));

        DockerSettings::fromArray($settings, '8.4');
    }
}
