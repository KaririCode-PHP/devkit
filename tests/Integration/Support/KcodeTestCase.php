<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Integration\Support;

use FilesystemIterator;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Runs the real `bin/kcode` against a throw-away project.
 *
 * Docker and Composer are replaced by stand-in scripts (KCODE_DOCKER_BINARY,
 * COMPOSER_BINARY) that log their arguments, so the full CLI wiring is
 * exercised without network access or a container engine.
 */
abstract class KcodeTestCase extends TestCase
{
    protected string $projectRoot;
    protected string $dockerLog;
    protected string $composerLog;
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/kcode_integration_' . uniqid();
        $this->projectRoot = $this->sandbox . '/project';
        mkdir($this->projectRoot . '/src', 0o777, true);
        mkdir($this->projectRoot . '/tests/Unit', 0o777, true);
        mkdir($this->sandbox . '/bin', 0o777, true);

        $this->projectRoot = (string) realpath($this->projectRoot);
        $this->dockerLog = $this->sandbox . '/docker.log';
        $this->composerLog = $this->sandbox . '/composer.log';

        $this->writeComposerJson([
            'name' => 'kariricode/demo',
            'require' => ['php' => '^8.4'],
            'autoload' => ['psr-4' => ['KaririCode\\Demo\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['KaririCode\\Demo\\Tests\\' => 'tests/']],
        ]);

        $this->writeFakeDocker();
        $this->writeFakeComposer();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->sandbox);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    protected function kcode(array $arguments, ?string $workingDirectory = null, array $environment = []): array
    {
        $binary = \dirname(__DIR__, 3) . '/bin/kcode';
        $variables = array_replace(getenv(), [
            'KCODE_DOCKER_BINARY' => $this->sandbox . '/bin/docker',
            'COMPOSER_BINARY' => $this->sandbox . '/bin/composer',
            'KCODE_FAKE_DOCKER_LOG' => $this->dockerLog,
            'KCODE_FAKE_COMPOSER_LOG' => $this->composerLog,
            'KCODE_FAKE_RUNNING' => $this->sandbox . '/running',
            // Isolate from the caller's settings; tests opt in explicitly.
            'KCODE_NO_PROVISION' => '',
            'KCODE_DOCKER' => '',
            'XDEBUG_MODE' => '',
            'NO_COLOR' => '1',
        ], $environment);

        $process = proc_open(
            ['php', $binary, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory ?? $this->projectRoot,
            $variables,
        );

        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Make the fake `docker compose ps` report the php service as running. */
    protected function markContainerRunning(): void
    {
        touch($this->sandbox . '/running');
    }

    protected function dockerCalls(): string
    {
        return is_file($this->dockerLog) ? (string) file_get_contents($this->dockerLog) : '';
    }

    protected function composerCalls(): string
    {
        return is_file($this->composerLog) ? (string) file_get_contents($this->composerLog) : '';
    }

    /** @param array<string, mixed> $manifest */
    protected function writeComposerJson(array $manifest): void
    {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /** Stand-in docker: logs every call; `ps --quiet` prints an id only when marked running. */
    private function writeFakeDocker(): void
    {
        $this->writeScript('docker', <<<'SH'
            #!/bin/sh
            echo "docker $*" >> "$KCODE_FAKE_DOCKER_LOG"
            case " $* " in
              *" ps --status running --quiet "*) [ -f "$KCODE_FAKE_RUNNING" ] && echo 4f2c9a ;;
              *" up "*) touch "$KCODE_FAKE_RUNNING" ;;
            esac
            exit 0
            SH);
    }

    /**
     * Stand-in composer: logs every call and creates what `install` would write.
     * In `.kcode/` it also creates tool stubs that print their name and arguments.
     */
    private function writeFakeComposer(): void
    {
        $this->writeScript('composer', <<<'SH'
            #!/bin/sh
            echo "composer $*" >> "$KCODE_FAKE_COMPOSER_LOG"
            target="$PWD"
            for argument in "$@"; do
              case "$argument" in --working-dir=*) target="${argument#--working-dir=}" ;; esac
            done
            if [ "$1" = "install" ]; then
              mkdir -p "$target/vendor/bin"
              echo '<?php' > "$target/vendor/autoload.php"
              case "$target" in
                *.kcode)
                  [ -f "$target/composer.lock" ] || echo '{"packages": [], "content-hash": "resolved"}' > "$target/composer.lock"
                  for tool in phpunit phpstan psalm php-cs-fixer rector infection; do
                    printf '#!/bin/sh\necho "%s $*"\n' "$tool" > "$target/vendor/bin/$tool"
                    chmod +x "$target/vendor/bin/$tool"
                  done
                  ;;
              esac
            fi
            exit 0
            SH);
    }

    private function writeScript(string $name, string $content): void
    {
        $path = $this->sandbox . '/bin/' . $name;
        file_put_contents($path, $content . "\n");
        chmod($path, 0o755);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
