<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Runner;

use FilesystemIterator;
use KaririCode\Devkit\Core\ProcessExecutor;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\Runner\AbstractToolRunner;
use KaririCode\Devkit\Runner\CsFixerRunner;
use KaririCode\Devkit\Runner\PhpStanRunner;
use KaririCode\Devkit\Runner\PhpUnitRunner;
use KaririCode\Devkit\Runner\PsalmRunner;
use KaririCode\Devkit\Runner\RectorApplyRunner;
use KaririCode\Devkit\Runner\RectorRunner;
use KaririCode\Devkit\ValueObject\DockerSettings;
use KaririCode\Devkit\ValueObject\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Root-level tool configs (Spec V4.3.3 §31) win over the generated ones
 * only when the project opts in with devkit.php → 'root_configs' => true.
 */
#[CoversClass(AbstractToolRunner::class)]
#[CoversClass(PhpUnitRunner::class)]
#[CoversClass(PhpStanRunner::class)]
#[CoversClass(PsalmRunner::class)]
#[CoversClass(CsFixerRunner::class)]
#[CoversClass(RectorRunner::class)]
#[CoversClass(RectorApplyRunner::class)]
#[UsesClass(ProcessExecutor::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(DockerSettings::class)]
#[UsesClass(ToolResult::class)]
final class ConfigPrecedenceTest extends TestCase
{
    private string $root;
    private ProjectContext $context;
    private ProcessExecutor $executor;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devkit_precedence_' . uniqid();
        mkdir($this->root . '/.kcode/vendor/bin', 0o777, true);

        // Stand-ins print the arguments they receive.
        foreach (['phpunit', 'phpstan', 'psalm', 'php-cs-fixer', 'rector'] as $tool) {
            $binary = $this->root . '/.kcode/vendor/bin/' . $tool;
            file_put_contents($binary, "#!/bin/sh\necho \"\$@\"\n");
            chmod($binary, 0o755);
        }

        $this->root = (string) realpath($this->root);
        $this->executor = new ProcessExecutor($this->root);
        $this->context = $this->makeContext(rootConfigs: true);
    }

    private function makeContext(bool $rootConfigs): ProjectContext
    {
        return new ProjectContext(
            projectRoot: $this->root,
            projectName: 'kariricode/property-inspector',
            namespace: 'KaririCode\\PropertyInspector',
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
            rootConfigs: $rootConfigs,
        );
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    /** @return iterable<string, array{class-string<AbstractToolRunner>, string, list<string>}> */
    public static function runners(): iterable
    {
        yield 'phpunit' => [PhpUnitRunner::class, 'phpunit.xml.dist', ['phpunit.xml', 'phpunit.xml.dist']];
        yield 'phpstan' => [PhpStanRunner::class, 'phpstan.neon', ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon']];
        yield 'psalm' => [PsalmRunner::class, 'psalm.xml', ['psalm.xml', 'psalm.xml.dist']];
        yield 'cs-fixer' => [CsFixerRunner::class, 'php-cs-fixer.php', ['.php-cs-fixer.php', '.php-cs-fixer.dist.php']];
        yield 'rector' => [RectorRunner::class, 'rector.php', ['rector.php']];
        yield 'rector-apply' => [RectorApplyRunner::class, 'rector.php', ['rector.php']];
    }

    /**
     * @param class-string<AbstractToolRunner> $runnerClass
     * @param list<string>                     $rootCandidates
     */
    #[Test]
    #[DataProvider('runners')]
    public function usesTheGeneratedConfigWithoutARootConfig(string $runnerClass, string $generated, array $rootCandidates): void
    {
        $output = $this->runWith($runnerClass);

        $this->assertStringContainsString("{$this->root}/.kcode/{$generated}", $output);
    }

    /**
     * @param class-string<AbstractToolRunner> $runnerClass
     * @param list<string>                     $rootCandidates
     */
    #[Test]
    #[DataProvider('runners')]
    public function prefersEachRootConfigCandidate(string $runnerClass, string $generated, array $rootCandidates): void
    {
        foreach ($rootCandidates as $candidate) {
            touch($this->root . '/' . $candidate);

            $output = $this->runWith($runnerClass);

            $this->assertStringContainsString("{$this->root}/{$candidate}", $output);
            $this->assertStringNotContainsString("/.kcode/{$generated}", $output);

            unlink($this->root . '/' . $candidate);
        }
    }

    /**
     * @param class-string<AbstractToolRunner> $runnerClass
     * @param list<string>                     $rootCandidates
     */
    #[Test]
    #[DataProvider('runners')]
    public function ignoresRootConfigsWithoutTheOptIn(string $runnerClass, string $generated, array $rootCandidates): void
    {
        // A stale root config left in a migrated component must not replace the generated rules.
        $this->context = $this->makeContext(rootConfigs: false);

        foreach ($rootCandidates as $candidate) {
            touch($this->root . '/' . $candidate);
        }

        $output = $this->runWith($runnerClass);

        $this->assertStringContainsString("{$this->root}/.kcode/{$generated}", $output);

        foreach ($rootCandidates as $candidate) {
            unlink($this->root . '/' . $candidate);
        }
    }

    #[Test]
    public function followsTheToolsOwnLookupOrder(): void
    {
        touch($this->root . '/phpunit.xml');
        touch($this->root . '/phpunit.xml.dist');

        $this->assertStringContainsString("{$this->root}/phpunit.xml ", $this->runWith(PhpUnitRunner::class));
    }

    /** @param class-string<AbstractToolRunner> $runnerClass */
    private function runWith(string $runnerClass): string
    {
        $runner = PhpUnitRunner::class === $runnerClass
            ? new PhpUnitRunner($this->executor, $this->context, static fn (): bool => true)
            : new $runnerClass($this->executor, $this->context);

        return $runner->run()->stdout;
    }
}
