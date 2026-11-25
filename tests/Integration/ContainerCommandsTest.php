<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Integration;

use KaririCode\Devkit\Command\AbstractContainerCommand;
use KaririCode\Devkit\Command\DownCommand;
use KaririCode\Devkit\Command\ExecCommand;
use KaririCode\Devkit\Command\LogsCommand;
use KaririCode\Devkit\Command\PsCommand;
use KaririCode\Devkit\Command\ShellCommand;
use KaririCode\Devkit\Command\UpCommand;
use KaririCode\Devkit\Tests\Integration\Support\KcodeTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * `kcode up | down | shell | exec | ps | logs` through the real CLI,
 * with a stand-in docker binary.
 */
#[CoversClass(AbstractContainerCommand::class)]
#[CoversClass(UpCommand::class)]
#[CoversClass(DownCommand::class)]
#[CoversClass(ShellCommand::class)]
#[CoversClass(ExecCommand::class)]
#[CoversClass(PsCommand::class)]
#[CoversClass(LogsCommand::class)]
final class ContainerCommandsTest extends KcodeTestCase
{
    private function composePrefix(): string
    {
        return "compose --file {$this->projectRoot}/.kcode/docker/compose.yaml --project-name kcode-kariricode-demo";
    }

    #[Test]
    public function upGeneratesTheRuntimeAndStartsIt(): void
    {
        $result = $this->kcode(['up']);

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertFileExists($this->projectRoot . '/.kcode/docker/compose.yaml');
        $this->assertFileExists($this->projectRoot . '/.kcode/docker/Dockerfile');
        $this->assertFileExists($this->projectRoot . '/.kcode/docker/php.ini');
        $this->assertStringContainsString("{$this->composePrefix()} up --detach --wait\n", $this->dockerCalls());
        $this->assertStringContainsString('Container running: kcode-kariricode-demo', $result['stdout']);
    }

    #[Test]
    public function upWithBuildForcesARebuild(): void
    {
        $this->kcode(['up', '--build']);

        $this->assertStringContainsString('up --detach --wait --build', $this->dockerCalls());
    }

    #[Test]
    public function downIsANoOpBeforeTheRuntimeExists(): void
    {
        $result = $this->kcode(['down']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('Nothing to stop', $result['stdout']);
        $this->assertSame('', $this->dockerCalls());
    }

    #[Test]
    public function downRemovesTheContainersAndOptionallyVolumes(): void
    {
        $this->kcode(['up']);
        $this->kcode(['down', '--volumes']);

        $this->assertStringContainsString("{$this->composePrefix()} down --remove-orphans --volumes", $this->dockerCalls());
    }

    #[Test]
    public function execRunsTheCommandInTheRunningContainer(): void
    {
        $this->markContainerRunning();

        $result = $this->kcode(['exec', '--', 'php', '-v']);

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString(
            "{$this->composePrefix()} {$this->execOptions()} php -v",
            $this->dockerCalls(),
        );
        $this->assertStringNotContainsString(' up ', $this->dockerCalls());
    }

    #[Test]
    public function execStartsAStoppedContainerFirst(): void
    {
        $this->kcode(['exec', 'composer', 'update']);

        $calls = $this->dockerCalls();

        $this->assertStringContainsString('up --detach --wait', $calls);
        $this->assertLessThan(strpos($calls, 'exec -T'), strpos($calls, 'up --detach'));
        $this->assertStringContainsString('php composer update', $calls);
    }

    #[Test]
    public function execWithoutACommandPrintsUsage(): void
    {
        $result = $this->kcode(['exec']);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Usage: kcode exec <command>', $result['stderr']);
    }

    #[Test]
    public function shellOpensTheRequestedShell(): void
    {
        $this->markContainerRunning();

        $this->kcode(['shell']);
        $this->kcode(['shell', 'bash']);

        $this->assertStringContainsString("--workdir {$this->projectRoot} php sh\n", $this->dockerCalls());
        $this->assertStringContainsString("--workdir {$this->projectRoot} php bash\n", $this->dockerCalls());
    }

    #[Test]
    public function psAndLogsPassArgumentsToCompose(): void
    {
        $this->kcode(['ps']);
        $this->kcode(['logs', '--follow', 'redis']);

        $this->assertStringContainsString("{$this->composePrefix()} ps\n", $this->dockerCalls());
        $this->assertStringContainsString("{$this->composePrefix()} logs --follow redis\n", $this->dockerCalls());
    }

    #[Test]
    public function containerCommandsWorkFromASubdirectory(): void
    {
        $result = $this->kcode(['up'], $this->projectRoot . '/tests/Unit');

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString($this->composePrefix(), $this->dockerCalls());
    }

    #[Test]
    public function dockerFlagRunsToolCommandsInTheContainer(): void
    {
        mkdir($this->projectRoot . '/.kcode/vendor/bin', 0o777, true);
        file_put_contents($this->projectRoot . '/.kcode/vendor/bin/phpunit', "#!/bin/sh\nexit 0\n");
        chmod($this->projectRoot . '/.kcode/vendor/bin/phpunit', 0o755);

        $result = $this->kcode(['test', '--docker']);

        $calls = $this->dockerCalls();

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('up --detach --wait', $calls);
        $this->assertStringContainsString(
            "php {$this->projectRoot}/.kcode/vendor/bin/phpunit --configuration {$this->projectRoot}/.kcode/phpunit.xml.dist",
            $calls,
        );
    }
}
