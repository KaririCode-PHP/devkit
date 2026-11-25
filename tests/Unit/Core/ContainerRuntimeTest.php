<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use KaririCode\Devkit\Core\ContainerRuntime;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\ValueObject\DockerSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerRuntime::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(DockerSettings::class)]
final class ContainerRuntimeTest extends TestCase
{
    private const string ROOT = '/home/dev/kariricode/validator';
    private const string COMPOSE_FILE = self::ROOT . '/.kcode/docker/compose.yaml';

    private function makeContext(string $projectName = 'kariricode/validator', string $root = self::ROOT): ProjectContext
    {
        return new ProjectContext(
            projectRoot: $root,
            projectName: $projectName,
            namespace: 'KaririCode\\Validator',
            phpVersion: '8.4',
            phpstanLevel: 9,
            psalmLevel: 3,
            sourceDirs: [],
            testDirs: [],
            excludeDirs: [],
            testSuites: [],
            coverageExclude: [],
            csFixerRules: [],
            rectorSets: [],
            toolVersions: [],
        );
    }

    /** @param array<string, string> $environment */
    private function makeRuntime(string $user = '', array $environment = []): ContainerRuntime
    {
        return new ContainerRuntime($this->makeContext(), 'docker', $user, $environment);
    }

    #[Test]
    public function composeTargetsTheGeneratedFileAndProjectName(): void
    {
        $this->assertSame(
            ['docker', 'compose', '--file', self::COMPOSE_FILE, '--project-name', 'kcode-kariricode-validator', 'ps'],
            $this->makeRuntime()->compose('ps'),
        );
    }

    #[Test]
    public function execRunsWithoutTtyFromTheProjectRoot(): void
    {
        $this->assertSame(
            [
                'docker', 'compose', '--file', self::COMPOSE_FILE, '--project-name', 'kcode-kariricode-validator',
                'exec', '-T', '--workdir', self::ROOT, 'php', 'vendor/bin/phpunit', '--testdox',
            ],
            $this->makeRuntime()->exec(['vendor/bin/phpunit', '--testdox']),
        );
    }

    #[Test]
    public function execAllocatesATtyWhenInteractive(): void
    {
        $command = $this->makeRuntime()->exec(['sh'], interactive: true);

        $this->assertNotContains('-T', $command);
        $this->assertSame('sh', $command[array_key_last($command)]);
    }

    #[Test]
    public function execRunsAsTheConfiguredUser(): void
    {
        $command = $this->makeRuntime('1000:1000')->exec(['php', '-v']);

        $this->assertSame(['--user', '1000:1000'], \array_slice($command, 8, 2));
    }

    #[Test]
    public function execForwardsOnlyXdebugVariables(): void
    {
        $command = $this->makeRuntime('', [
            'XDEBUG_MODE' => 'debug',
            'XDEBUG_SESSION' => 'PHPSTORM',
            'XDEBUG_CONFIG' => '',
            'SECRET_TOKEN' => 'abc',
        ])->exec(['php', '-v']);

        $this->assertContains('XDEBUG_MODE=debug', $command);
        $this->assertContains('XDEBUG_SESSION=PHPSTORM', $command);
        $this->assertNotContains('XDEBUG_CONFIG=', $command);
        $this->assertNotContains('SECRET_TOKEN=abc', $command);
    }

    #[Test]
    public function upStartsDetachedAndWaitsForTheServices(): void
    {
        $runtime = $this->makeRuntime();

        $this->assertSame(['up', '--detach', '--wait'], \array_slice($runtime->up(), 6));
        $this->assertSame(['up', '--detach', '--wait', '--build'], \array_slice($runtime->up(build: true), 6));
    }

    #[Test]
    public function downRemovesOrphansAndOptionallyVolumes(): void
    {
        $runtime = $this->makeRuntime();

        $this->assertSame(['down', '--remove-orphans'], \array_slice($runtime->down(), 6));
        $this->assertSame(['down', '--remove-orphans', '--volumes'], \array_slice($runtime->down(volumes: true), 6));
    }

    #[Test]
    public function statusListsTheRunningPhpService(): void
    {
        $this->assertSame(
            ['ps', '--status', 'running', '--quiet', 'php'],
            \array_slice($this->makeRuntime()->status(), 6),
        );
    }

    #[Test]
    public function isConfiguredReflectsTheGeneratedComposeFile(): void
    {
        $root = sys_get_temp_dir() . '/devkit_container_test_' . uniqid();
        $runtime = new ContainerRuntime($this->makeContext('kariricode/validator', $root), user: '');

        $this->assertFalse($runtime->isConfigured());

        mkdir($root . '/.kcode/docker', 0o777, true);
        file_put_contents($runtime->composeFile(), 'services: {}');

        $this->assertTrue($runtime->isConfigured());

        unlink($runtime->composeFile());
        rmdir($root . '/.kcode/docker');
        rmdir($root . '/.kcode');
        rmdir($root);
    }

    #[Test]
    public function projectNameIsAValidComposeProjectName(): void
    {
        $this->assertSame('kcode-kariricode-validator', ContainerRuntime::projectNameFor($this->makeContext()));
        $this->assertSame('kcode-acme-my-lib-v2', ContainerRuntime::projectNameFor($this->makeContext('Acme/My_Lib.v2')));
        $this->assertSame('kcode-project', ContainerRuntime::projectNameFor($this->makeContext('///')));
    }
}
