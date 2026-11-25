<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use FilesystemIterator;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\Core\ToolchainState;
use KaririCode\Devkit\ValueObject\DockerSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ToolchainState::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(DockerSettings::class)]
final class ToolchainStateTest extends TestCase
{
    private string $root;
    private ProjectContext $context;
    private ToolchainState $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/devkit_state_test_' . uniqid();
        mkdir($this->root . '/.kcode/vendor', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{"name": "kariricode/validator"}');
        file_put_contents($this->root . '/.kcode/composer.json', '{"require": {"phpunit/phpunit": "^12.5"}}');

        $this->context = new ProjectContext(
            projectRoot: $this->root,
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
        $this->state = new ToolchainState('1.2.0');
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

    #[Test]
    public function nothingIsCurrentBeforeTheFirstRecord(): void
    {
        $this->assertFalse($this->state->configsCurrent($this->context));
        $this->assertFalse($this->state->toolsInputsUnchanged($this->context));
        $this->assertFalse($this->state->toolsCurrent($this->context));
    }

    #[Test]
    public function recordedConfigsStayCurrentUntilAnInputChanges(): void
    {
        $this->state->recordConfigs($this->context);
        $this->assertTrue($this->state->configsCurrent($this->context));

        file_put_contents($this->root . '/devkit.php', "<?php return ['phpstan_level' => 8];");
        $this->assertFalse($this->state->configsCurrent($this->context));

        $this->state->recordConfigs($this->context);
        file_put_contents($this->root . '/composer.json', '{"name": "kariricode/validator", "require": {"php": "^8.5"}}');
        $this->assertFalse($this->state->configsCurrent($this->context));
    }

    #[Test]
    public function aNewDevkitVersionInvalidatesTheConfigs(): void
    {
        $this->state->recordConfigs($this->context);

        $this->assertFalse(new ToolchainState('1.3.0')->configsCurrent($this->context));
    }

    #[Test]
    public function toolsAreCurrentOnlyWithAnInstalledAutoloader(): void
    {
        $this->state->recordTools($this->context);

        $this->assertTrue($this->state->toolsInputsUnchanged($this->context));
        $this->assertFalse($this->state->toolsCurrent($this->context));

        file_put_contents($this->root . '/.kcode/vendor/autoload.php', '<?php');
        $this->assertTrue($this->state->toolsCurrent($this->context));
    }

    #[Test]
    public function manifestOrLockChangesInvalidateTheTools(): void
    {
        file_put_contents($this->root . '/.kcode/vendor/autoload.php', '<?php');
        $this->state->recordTools($this->context);

        file_put_contents($this->root . '/devkit.lock', '{"packages": []}');
        $this->assertFalse($this->state->toolsCurrent($this->context));

        $this->state->recordTools($this->context);
        file_put_contents($this->root . '/.kcode/composer.json', '{"require": {"phpunit/phpunit": "^13.0"}}');
        $this->assertFalse($this->state->toolsCurrent($this->context));
    }

    #[Test]
    public function stateIsPersistedAsJsonNextToTheConfigs(): void
    {
        $this->state->recordConfigs($this->context);
        $this->state->recordTools($this->context);

        $stored = json_decode((string) file_get_contents($this->root . '/.kcode/state.json'), true);

        $this->assertIsArray($stored);
        $this->assertSame(['configs', 'devkit', 'tools'], array_keys($stored));
        $this->assertSame('1.2.0', $stored['devkit']);
        $this->assertSame($this->state->configsFingerprint($this->context), $stored['configs']);
    }

    #[Test]
    public function corruptStateIsTreatedAsMissing(): void
    {
        file_put_contents($this->root . '/.kcode/state.json', '{not json');

        $this->assertFalse($this->state->configsCurrent($this->context));

        $this->state->recordConfigs($this->context);
        $this->assertTrue($this->state->configsCurrent($this->context));
    }

    #[Test]
    public function recordingWithoutKcodeDirectoryIsANoOp(): void
    {
        $context = new ProjectContext(
            projectRoot: $this->root . '/missing',
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

        $this->state->recordConfigs($context);

        $this->assertFileDoesNotExist($this->root . '/missing/.kcode/state.json');
    }
}
