<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use KaririCode\Devkit\Core\ContainerRuntime;
use KaririCode\Devkit\Core\ProcessExecutor;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\ValueObject\DockerSettings;
use KaririCode\Devkit\ValueObject\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessExecutor::class)]
#[UsesClass(ToolResult::class)]
#[UsesClass(ContainerRuntime::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(DockerSettings::class)]
final class ProcessExecutorTest extends TestCase
{
    private string $projectRoot;
    private ProcessExecutor $executor;

    protected function setUp(): void
    {
        $this->projectRoot = (string) realpath(\dirname(__DIR__, 3));
        $this->executor = new ProcessExecutor($this->projectRoot);
    }

    #[Test]
    public function executeRunsCommandAndCapturesOutput(): void
    {
        $result = $this->executor->execute('php-inline', ['php', '-r', 'echo "hello";']);
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame(0, $result->exitCode);
        $this->assertStringContainsString('hello', $result->stdout);
    }

    #[Test]
    public function executeReturnsTrueSuccessOnExitCode0(): void
    {
        $result = $this->executor->execute('test-tool', ['php', '-r', 'echo "ok";']);
        $this->assertTrue($result->success);
    }

    #[Test]
    public function executeReturnsFalseSuccessOnNonZeroExit(): void
    {
        $result = $this->executor->execute('false-cmd', ['php', '-r', 'exit(1);']);
        $this->assertSame(1, $result->exitCode);
        $this->assertFalse($result->success);
    }

    #[Test]
    public function executeRecordsElapsedTime(): void
    {
        $result = $this->executor->execute('test-tool', ['php', '-r', 'echo "ok";']);
        $this->assertGreaterThanOrEqual(0.0, $result->elapsedSeconds);
    }

    #[Test]
    public function executeReturnsCorrectToolName(): void
    {
        $result = $this->executor->execute('mytool', ['php', '-r', 'echo "x";']);
        $this->assertSame('mytool', $result->toolName);
    }

    #[Test]
    public function executeCapturesStderr(): void
    {
        $result = $this->executor->execute('php-err', ['php', '-r', 'fwrite(STDERR, "err-msg");']);
        $this->assertStringContainsString('err-msg', $result->stderr);
    }

    #[Test]
    public function executeDrainsLargeStderrWithoutDeadlock(): void
    {
        // 256 KiB on stderr overflows the pipe buffer; sequential reads would block forever.
        $script = 'fwrite(STDERR, str_repeat("e", 262144)); echo "done";';

        $result = $this->executor->execute('php-noisy', ['php', '-r', $script]);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('done', $result->stdout);
        $this->assertSame(262144, \strlen($result->stderr));
    }

    #[Test]
    public function resolveBinaryFindsLocalVendorBin(): void
    {
        // phpunit is installed as a dev dependency — vendor/bin/phpunit must exist
        $binary = $this->executor->resolveBinary('vendor/bin/phpunit');
        $this->assertNotNull($binary);
        $this->assertStringContainsString('phpunit', $binary);
    }

    #[Test]
    public function resolveBinaryReturnsNullForNonExistentTool(): void
    {
        // Use an executor with a path where the binary won't exist locally OR globally
        $executor = new ProcessExecutor('/tmp');
        $result = $executor->resolveBinary('vendor/bin/nonexistent-tool-xyz-999');
        $this->assertNull($result);
    }

    #[Test]
    public function executeReturnsNonZeroForFailingCommand(): void
    {
        $result = $this->executor->execute('exit-1', ['php', '-r', 'exit(2);']);
        $this->assertSame(2, $result->exitCode);
        $this->assertFalse($result->success);
    }

    #[Test]
    public function toolResultOutputCombinesStdoutAndStderr(): void
    {
        $result = $this->executor->execute('test', ['php', '-r', 'echo "out"; fwrite(STDERR, "err");']);
        $output = $result->output();
        $this->assertStringContainsString('out', $output);
    }

    #[Test]
    public function toolResultOutputReturnsNoOutputWhenBothEmpty(): void
    {
        $result = new ToolResult('test', 0, '', '', 0.0);
        $this->assertSame('(no output)', $result->output());
    }

    // ── Container mode ────────────────────────────────────────────

    #[Test]
    public function hostModeIsNotContainerized(): void
    {
        $this->assertFalse($this->executor->isContainerized());
        $this->assertSame($this->projectRoot, $this->executor->workingDirectory());
        $this->assertSame(0, $this->executor->ensureContainer());
    }

    #[Test]
    public function containerModeWrapsCommandsWithComposeExec(): void
    {
        $executor = new ProcessExecutor($this->projectRoot, $this->fakeRuntime('echo'));

        $result = $executor->execute('phpunit', ['vendor/bin/phpunit', '--testdox']);

        $this->assertTrue($executor->isContainerized());
        $this->assertStringContainsString(
            "exec -T --workdir {$this->projectRoot} php vendor/bin/phpunit --testdox",
            $result->stdout,
        );
    }

    #[Test]
    public function containerModeCanRunHostCommandsUnwrapped(): void
    {
        $executor = new ProcessExecutor($this->projectRoot, $this->fakeRuntime('echo'));

        $result = $executor->execute('php', ['php', '-r', 'echo "host";'], inContainer: false);

        $this->assertSame('host', $result->stdout);
    }

    #[Test]
    public function containerModeSkipsTheGlobalPathTier(): void
    {
        $executor = new ProcessExecutor('/tmp', $this->fakeRuntime('echo'));

        // `php` is on the host PATH, but not resolvable for the container.
        $this->assertNull($executor->resolveBinary('vendor/bin/php'));
    }

    #[Test]
    public function ensureContainerDoesNothingWhenTheServiceIsRunning(): void
    {
        $log = sys_get_temp_dir() . '/devkit_fake_docker_' . uniqid() . '.log';
        $executor = new ProcessExecutor($this->projectRoot, $this->fakeRuntime($this->fakeDocker($log, running: true)));

        $this->assertSame(0, $executor->ensureContainer());
        $this->assertStringNotContainsString(' up ', (string) file_get_contents($log));

        unlink($log);
    }

    #[Test]
    public function ensureContainerStartsAStoppedService(): void
    {
        $log = sys_get_temp_dir() . '/devkit_fake_docker_' . uniqid() . '.log';
        $executor = new ProcessExecutor($this->projectRoot, $this->fakeRuntime($this->fakeDocker($log, running: false)));

        $this->assertSame(0, $executor->ensureContainer());
        $this->assertStringContainsString(' up --detach --wait', (string) file_get_contents($log));

        unlink($log);
    }

    #[Test]
    public function passthruReturnsTheExitCode(): void
    {
        $this->assertSame(0, $this->executor->passthru(['php', '-r', 'exit(0);']));
        $this->assertSame(3, $this->executor->passthru(['php', '-r', 'exit(3);']));
    }

    private function fakeRuntime(string $dockerBinary): ContainerRuntime
    {
        $context = new ProjectContext(
            projectRoot: $this->projectRoot,
            projectName: 'kariricode/devkit',
            namespace: 'KaririCode\\Devkit',
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

        return new ContainerRuntime($context, $dockerBinary, user: '', environment: []);
    }

    /** A docker stand-in that logs its arguments; `ps` prints a container id only when running. */
    private function fakeDocker(string $log, bool $running): string
    {
        $script = $log . '.sh';
        $psOutput = $running ? 'echo 4f2c9a' : 'true';

        file_put_contents($script, <<<SH
            #!/bin/sh
            echo "docker \$*" >> '{$log}'
            case " \$* " in *" ps "*) {$psOutput} ;; esac
            exit 0
            SH);
        chmod($script, 0o755);
        register_shutdown_function(static fn (): bool => @unlink($script));

        return $script;
    }
}
