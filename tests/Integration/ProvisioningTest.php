<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Integration;

use KaririCode\Devkit\Command\CleanCommand;
use KaririCode\Devkit\Command\InitCommand;
use KaririCode\Devkit\Core\Devkit;
use KaririCode\Devkit\Core\ToolchainState;
use KaririCode\Devkit\Tests\Integration\Support\KcodeTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A global kcode provisions `.kcode/` on demand inside KaririCode components.
 */
#[CoversClass(Devkit::class)]
#[CoversClass(ToolchainState::class)]
#[CoversClass(InitCommand::class)]
#[CoversClass(CleanCommand::class)]
final class ProvisioningTest extends KcodeTestCase
{
    #[Test]
    public function firstToolCommandProvisionsTheWholeToolchain(): void
    {
        $result = $this->kcode(['test']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('Generated', $result['stdout']);
        $this->assertStringContainsString('Installing the toolchain into .kcode/vendor/', $result['stdout']);
        $this->assertStringContainsString('Installing project dependencies', $result['stdout']);
        $this->assertStringContainsString("install --working-dir={$this->projectRoot}/.kcode ", $this->composerCalls());
        $this->assertStringContainsString("install --working-dir={$this->projectRoot} --no-interaction", $this->composerCalls());
        $this->assertFileExists($this->projectRoot . '/.kcode/phpunit.xml.dist');
        $this->assertFileExists($this->projectRoot . '/.kcode/state.json');
        // The stub phpunit received the generated configuration.
        $this->assertStringContainsString("phpunit --configuration {$this->projectRoot}/.kcode/phpunit.xml.dist", $result['stdout']);
        $this->assertStringContainsString('.kcode/', (string) file_get_contents($this->projectRoot . '/.gitignore'));
    }

    #[Test]
    public function provisionedToolchainIsReusedOnTheNextRun(): void
    {
        $this->kcode(['test']);
        unlink($this->composerLog);

        $result = $this->kcode(['analyse']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertSame('', $this->composerCalls());
        $this->assertStringNotContainsString('Generated', $result['stdout']);
    }

    #[Test]
    public function provisioningWorksFromASubdirectory(): void
    {
        $result = $this->kcode(['test'], $this->projectRoot . '/tests/Unit');

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertDirectoryExists($this->projectRoot . '/.kcode/vendor/bin');
        $this->assertDirectoryDoesNotExist($this->projectRoot . '/tests/Unit/.kcode');
    }

    #[Test]
    public function editingDevkitConfigRegeneratesConfigsOnly(): void
    {
        $this->kcode(['test']);
        unlink($this->composerLog);
        file_put_contents($this->projectRoot . '/devkit.php', "<?php return ['phpstan_level' => 8];");

        $result = $this->kcode(['analyse']);

        $this->assertStringContainsString('Generated', $result['stdout']);
        $this->assertStringContainsString('level: 8', (string) file_get_contents($this->projectRoot . '/.kcode/phpstan.neon'));
        $this->assertSame('', $this->composerCalls(), 'The tool manifest did not change: no reinstall.');
    }

    #[Test]
    public function changingToolConstraintsReinstallsAndDropsTheStaleLock(): void
    {
        $this->kcode(['test']);
        unlink($this->composerLog);
        file_put_contents($this->projectRoot . '/.kcode/composer.lock', '{"stale": true}');
        file_put_contents($this->projectRoot . '/devkit.php', "<?php return ['tools' => ['mockery/mockery' => '^1.6']];");

        $result = $this->kcode(['test']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('Installing the toolchain', $result['stdout']);
        $this->assertStringContainsString('"mockery/mockery": "^1.6"', (string) file_get_contents($this->projectRoot . '/.kcode/composer.json'));
        $this->assertStringNotContainsString('stale', (string) file_get_contents($this->projectRoot . '/.kcode/composer.lock'));
    }

    #[Test]
    public function committedDevkitLockPinsTheToolchain(): void
    {
        file_put_contents($this->projectRoot . '/devkit.lock', '{"pinned": true}');

        $this->kcode(['test']);

        $this->assertSame('{"pinned": true}', file_get_contents($this->projectRoot . '/.kcode/composer.lock'));
    }

    #[Test]
    public function initWithLockWritesDevkitLock(): void
    {
        $result = $this->kcode(['init', '--lock']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertFileEquals($this->projectRoot . '/.kcode/composer.lock', $this->projectRoot . '/devkit.lock');
        $this->assertStringContainsString('Pinned tool versions in devkit.lock', $result['stdout']);
    }

    #[Test]
    public function thirdPartyProjectsAreNotProvisionedAutomatically(): void
    {
        $this->writeComposerJson(['name' => 'acme/library']);

        $result = $this->kcode(['test']);

        $this->assertSame('', $this->composerCalls());
        $this->assertDirectoryDoesNotExist($this->projectRoot . '/.kcode');
        $this->assertStringContainsString('run "kcode init"', $result['stdout']);
    }

    #[Test]
    public function devkitConfigOptsAThirdPartyProjectIn(): void
    {
        $this->writeComposerJson(['name' => 'acme/library']);
        file_put_contents($this->projectRoot . '/devkit.php', '<?php return [];');

        $this->kcode(['test']);

        $this->assertStringContainsString('install --working-dir=', $this->composerCalls());
    }

    #[Test]
    public function provisioningCanBeDisabled(): void
    {
        $result = $this->kcode(['test'], environment: ['KCODE_NO_PROVISION' => '1']);

        $this->assertSame('', $this->composerCalls());
        $this->assertNotSame(0, $result['exitCode']);
    }

    #[Test]
    public function dockerModeInstallsTheToolchainInsideTheContainer(): void
    {
        $this->kcode(['test', '--docker']);

        $calls = $this->dockerCalls();

        $this->assertSame('', $this->composerCalls(), 'Host composer must not run in Docker mode.');
        $this->assertStringContainsString('up --detach --wait', $calls);
        $this->assertStringContainsString(
            "{$this->execOptions()} composer install --working-dir={$this->projectRoot}/.kcode",
            $calls,
        );
    }

    #[Test]
    public function cleanAllRemovesTheWholeKcodeDirectory(): void
    {
        $this->kcode(['test']);

        $result = $this->kcode(['clean', '--all']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertDirectoryDoesNotExist($this->projectRoot . '/.kcode');
    }

    #[Test]
    public function versionReportsTheReleaseUnderDevelopment(): void
    {
        $result = $this->kcode(['--version']);

        $this->assertSame('1.2.1', Devkit::version());
        $this->assertStringContainsString('KaririCode Devkit', $result['stdout']);
        $this->assertStringContainsString(Devkit::version(), $result['stdout']);
    }
}
