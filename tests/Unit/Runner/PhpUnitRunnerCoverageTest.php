<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Runner;

use KaririCode\Devkit\Core\ContainerRuntime;
use KaririCode\Devkit\Core\ProcessExecutor;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\Runner\AbstractToolRunner;
use KaririCode\Devkit\Runner\PhpUnitRunner;
use KaririCode\Devkit\ValueObject\DockerSettings;
use KaririCode\Devkit\ValueObject\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpUnitRunner::class)]
#[UsesClass(AbstractToolRunner::class)]
#[UsesClass(ProcessExecutor::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(ContainerRuntime::class)]
#[UsesClass(DockerSettings::class)]
#[UsesClass(ToolResult::class)]
final class PhpUnitRunnerCoverageTest extends TestCase
{
    private string $projectRoot;
    private ProjectContext $context;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/devkit_phpunit_runner_' . uniqid();
        mkdir($this->projectRoot . '/.kcode/vendor/bin', 0o777, true);

        // Stand-in for PHPUnit that prints the arguments it receives.
        $binary = $this->projectRoot . '/.kcode/vendor/bin/phpunit';
        file_put_contents($binary, "#!/bin/sh\necho \"\$@\"\n");
        chmod($binary, 0o755);

        $this->context = new ProjectContext(
            projectRoot: $this->projectRoot,
            projectName: 'kariricode/validator',
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

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->projectRoot . '/fake-docker-*.sh') ?: []);
        unlink($this->projectRoot . '/.kcode/vendor/bin/phpunit');
        rmdir($this->projectRoot . '/.kcode/vendor/bin');
        rmdir($this->projectRoot . '/.kcode/vendor');
        rmdir($this->projectRoot . '/.kcode');
        rmdir($this->projectRoot);
    }

    private function runner(bool $driverAvailable): PhpUnitRunner
    {
        return new PhpUnitRunner(
            new ProcessExecutor($this->projectRoot),
            $this->context,
            static fn (): bool => $driverAvailable,
        );
    }

    /** Container whose fake docker answers the coverage probe with $answer and echoes everything else. */
    private function containerAnswering(string $answer): ContainerRuntime
    {
        $script = $this->projectRoot . "/fake-docker-{$answer}.sh";
        file_put_contents($script, <<<SH
            #!/bin/sh
            case " \$* " in *" -r "*) echo {$answer} ;; *) echo "\$@" ;; esac
            SH);
        chmod($script, 0o755);

        return new ContainerRuntime($this->context, $script, user: '', environment: []);
    }

    #[Test]
    public function disablesCoverageWhenTheHostHasNoDriver(): void
    {
        $result = $this->runner(driverAvailable: false)->run();

        $this->assertStringEndsWith('--no-coverage', trim($result->stdout));
    }

    #[Test]
    public function keepsConfiguredCoverageWhenADriverIsLoaded(): void
    {
        $result = $this->runner(driverAvailable: true)->run();

        $this->assertStringNotContainsString('--no-coverage', $result->stdout);
    }

    #[Test]
    public function honoursAnExplicitCoverageRequest(): void
    {
        $result = $this->runner(driverAvailable: false)->run(['--coverage-html', '/tmp/coverage']);

        $this->assertStringNotContainsString('--no-coverage', $result->stdout);
        $this->assertStringContainsString('--coverage-html /tmp/coverage', $result->stdout);
    }

    #[Test]
    public function doesNotDuplicateAnExplicitNoCoverageFlag(): void
    {
        $result = $this->runner(driverAvailable: false)->run(['--no-coverage']);

        $this->assertSame(1, substr_count($result->stdout, '--no-coverage'));
    }

    #[Test]
    public function probesTheContainerPhpInDockerMode(): void
    {
        $withDriver = new PhpUnitRunner(new ProcessExecutor($this->projectRoot, $this->containerAnswering('1')), $this->context);
        $withoutDriver = new PhpUnitRunner(new ProcessExecutor($this->projectRoot, $this->containerAnswering('0')), $this->context);

        $covered = $withDriver->run();
        $uncovered = $withoutDriver->run();

        $this->assertStringContainsString('exec -T', $covered->stdout);
        $this->assertStringNotContainsString('--no-coverage', $covered->stdout);
        $this->assertStringEndsWith('--no-coverage', trim($uncovered->stdout));
    }

    #[Test]
    public function hostProbeReflectsTheLoadedExtensions(): void
    {
        $expected = (\extension_loaded('pcov') && (bool) \ini_get('pcov.enabled'))
            || (\extension_loaded('xdebug') && str_contains((string) (getenv('XDEBUG_MODE') ?: \ini_get('xdebug.mode')), 'coverage'));

        $this->assertSame($expected, PhpUnitRunner::hostHasCoverageDriver());
    }
}
