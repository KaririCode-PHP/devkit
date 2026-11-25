<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Integration;

use KaririCode\Devkit\Command\MigrateCommand;
use KaririCode\Devkit\Core\ComposerScriptRewriter;
use KaririCode\Devkit\Core\DevkitConfigWriter;
use KaririCode\Devkit\Core\MigrationDetector;
use KaririCode\Devkit\Core\MigrationService;
use KaririCode\Devkit\Tests\Integration\Support\KcodeTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * `kcode migrate` on a component laid out like the legacy KaririCode
 * template (kariricode/validator 1.x): .docker/, docker-compose.yml,
 * .env.example, a docker Makefile and QA tools in require-dev.
 */
#[CoversClass(MigrateCommand::class)]
#[CoversClass(MigrationDetector::class)]
#[CoversClass(MigrationService::class)]
#[CoversClass(ComposerScriptRewriter::class)]
#[CoversClass(DevkitConfigWriter::class)]
final class MigrateTest extends KcodeTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeComposerJson([
            'name' => 'kariricode/validator',
            'require' => ['php' => '^8.3'],
            'require-dev' => [
                'enlightn/security-checker' => '^2.0',
                'friendsofphp/php-cs-fixer' => '^3.51',
                'mockery/mockery' => '^1.6',
                'phpstan/phpstan' => '^1.10',
                'phpunit/phpunit' => '^11.0',
                'squizlabs/php_codesniffer' => '^3.9',
            ],
            'autoload' => ['psr-4' => ['KaririCode\\Validator\\' => 'src/']],
            'scripts' => [
                'test' => 'vendor/bin/phpunit --testdox',
                'check-style' => 'vendor/bin/phpcs',
                'fix-style' => 'vendor/bin/php-cs-fixer fix',
                'analyse' => 'vendor/bin/phpstan analyse',
            ],
        ]);

        mkdir($this->projectRoot . '/.docker/php', 0o777, true);
        file_put_contents($this->projectRoot . '/.docker/php/Dockerfile', "ARG PHP_VERSION=8.3\nFROM php:\${PHP_VERSION}-alpine\n");
        file_put_contents($this->projectRoot . '/.docker/php/kariricode-php.ini', "[PHP]\nmemory_limit = 256M\n");
        file_put_contents($this->projectRoot . '/docker-compose.yml', "services:\n  php:\n    container_name: kariricode-validator\n");
        file_put_contents($this->projectRoot . '/.env.example', "KARIRI_APP_ENV=develop\nKARIRI_PHP_VERSION=8.3\nKARIRI_PHP_PORT=9003");
        file_put_contents(
            $this->projectRoot . '/Makefile',
            "DC := docker-compose\nEXEC_PHP := \$(DC) exec -T php\n\ntest:\n\t\$(EXEC_PHP) ./vendor/bin/phpunit --testdox\n",
        );
        file_put_contents($this->projectRoot . '/phpunit.xml', '<phpunit/>');
        file_put_contents($this->projectRoot . '/phpcs.xml', '<ruleset/>');
        file_put_contents($this->projectRoot . '/phpinsights.php', '<?php return [];');
        file_put_contents($this->projectRoot . '/.php-cs-fixer.cache', '');
    }

    #[Test]
    public function dryRunReportsEveryCategoryWithoutTouchingFiles(): void
    {
        $before = (string) file_get_contents($this->projectRoot . '/composer.json');

        $result = $this->kcode(['migrate', '--dry-run']);

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString('Docker scaffolding', $result['stdout']);
        $this->assertStringContainsString("devkit.php 'tools'", $result['stdout']);
        $this->assertStringContainsString('composer.json scripts → kcode', $result['stdout']);
        $this->assertStringContainsString('Dry-run mode', $result['stdout']);
        $this->assertSame($before, file_get_contents($this->projectRoot . '/composer.json'));
        $this->assertDirectoryExists($this->projectRoot . '/.docker');
    }

    #[Test]
    public function migrationLeavesOnlySourceAndKcodeConventions(): void
    {
        $result = $this->kcode(['migrate', '--no-interaction']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        foreach (['.docker', 'docker-compose.yml', '.env.example', 'Makefile', 'phpunit.xml', 'phpcs.xml', 'phpinsights.php', '.php-cs-fixer.cache'] as $path) {
            $this->assertFileDoesNotExist($this->projectRoot . '/' . $path, "{$path} should be removed");
        }

        /** @var array{require-dev: array<string, string>, scripts: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);

        $this->assertSame([], $composer['require-dev']);
        $this->assertSame([
            'test' => 'kcode test --testdox',
            'check-style' => 'kcode cs:fix --check',
            'fix-style' => 'kcode cs:fix',
            'analyse' => 'kcode analyse',
        ], $composer['scripts']);
        $this->assertStringContainsString("'mockery/mockery' => '^1.6'", (string) file_get_contents($this->projectRoot . '/devkit.php'));
        $this->assertStringContainsString('composer update', $result['stdout']);
        $this->assertStringContainsString('kcode up', $result['stdout']);
    }

    #[Test]
    public function keepConfigsPreservesRootToolConfigs(): void
    {
        $result = $this->kcode(['migrate', '--no-interaction', '--keep-configs']);

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertFileExists($this->projectRoot . '/phpunit.xml');
        $this->assertFileDoesNotExist($this->projectRoot . '/phpcs.xml');
        $this->assertFileDoesNotExist($this->projectRoot . '/.docker');
    }

    #[Test]
    public function migratedComponentRunsThroughKcodeAlone(): void
    {
        $this->kcode(['migrate', '--no-interaction']);

        $result = $this->kcode(['test']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('"mockery/mockery": "^1.6"', (string) file_get_contents($this->projectRoot . '/.kcode/composer.json'));
        $this->assertStringContainsString("phpunit --configuration {$this->projectRoot}/.kcode/phpunit.xml.dist", $result['stdout']);
    }

    #[Test]
    public function secondRunHasNothingLeftToMigrate(): void
    {
        $this->kcode(['migrate', '--no-interaction']);

        $result = $this->kcode(['migrate', '--no-interaction']);

        $this->assertStringContainsString('Nothing to migrate', $result['stdout']);
    }
}
