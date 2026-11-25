<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

use KaririCode\Devkit\ValueObject\ToolResult;

use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Generic process executor used by all tool runners via composition.
 *
 * Handles process spawning, stdout/stderr capture, timing, and
 * exit-code collection. Binary resolution follows a three-tier
 * strategy:
 *
 *   1. `.kcode/vendor/bin/` (toolchain installed by kcode)
 *   2. Project-local `vendor/bin/` (composer-installed tools)
 *   3. Global PATH (system-wide tool installations; host mode only)
 *
 * When built with a ContainerRuntime (`--docker`), every command runs
 * through `docker compose exec` in the project's PHP container. The
 * project is mounted at the same absolute path, so commands need no
 * path translation.
 *
 * @since 1.0.0
 */
final readonly class ProcessExecutor
{
    public function __construct(
        private string $workingDirectory,
        private ?ContainerRuntime $container = null,
    ) {
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /** Whether commands run inside the project's PHP container. */
    public function isContainerized(): bool
    {
        return null !== $this->container;
    }

    /**
     * Execute a command and capture the result.
     *
     * @param list<string> $command     Full command with arguments.
     * @param bool         $inContainer Wrap with `docker compose exec` when containerized.
     */
    public function execute(string $toolName, array $command, bool $inContainer = true): ToolResult
    {
        $command = $this->prepare($command, $inContainer, false);
        $start = hrtime(true);

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workingDirectory,
        );

        if (! \is_resource($process)) {
            return new ToolResult(
                toolName: $toolName,
                exitCode: 127,
                stdout: '',
                stderr: 'Failed to spawn process: ' . implode(' ', $command),
                elapsedSeconds: 0.0,
            );
        }

        fclose($pipes[0]);

        [$stdout, $stderr] = $this->drain($pipes[1], $pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;

        return new ToolResult(
            toolName: $toolName,
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            elapsedSeconds: round($elapsed, 3),
        );
    }

    /**
     * Run a command attached to the terminal (live output, interactive input).
     *
     * @param list<string> $command
     * @param bool         $inContainer Wrap with `docker compose exec` when containerized.
     * @param bool         $interactive Allocate a TTY in the container (only when STDIN is a terminal).
     * @return int Exit code (127 when the process cannot be spawned)
     */
    public function passthru(array $command, bool $inContainer = true, bool $interactive = false): int
    {
        $command = $this->prepare($command, $inContainer, $interactive && stream_isatty(STDIN));

        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $this->workingDirectory);

        if (! \is_resource($process)) {
            return 127;
        }

        return proc_close($process);
    }

    /**
     * Start the PHP container when commands are containerized and it is not running.
     *
     * @return int 0 when the container is running (or not needed), the `docker compose up` exit code otherwise
     */
    public function ensureContainer(): int
    {
        if (null === $this->container) {
            return 0;
        }

        $status = $this->execute('docker', $this->container->status(), false);

        if ($status->success && '' !== trim($status->stdout)) {
            return 0;
        }

        return $this->passthru($this->container->up(), false);
    }

    /**
     * Resolve a tool binary path using the three-tier strategy.
     *
     * In container mode the global PATH tier is skipped: a binary on the
     * host PATH does not exist inside the container.
     *
     * @param string $vendorBin Relative path like "vendor/bin/phpunit"
     */
    public function resolveBinary(string $vendorBin): ?string
    {
        $basename = basename($vendorBin);

        // Tier 1: .kcode/vendor/bin/ — tools installed by `kcode init`
        // This is the primary resolution path for the kcode toolchain.
        $kcodeBin = $this->workingDirectory . '/.kcode/vendor/bin/' . $basename;
        if (is_file($kcodeBin) && is_executable($kcodeBin)) {
            return $kcodeBin;
        }

        // Tier 2: Project-local vendor binary (e.g. package has devkit as require-dev)
        $localBin = $this->workingDirectory . '/' . $vendorBin;
        if (is_file($localBin) && is_executable($localBin)) {
            return $localBin;
        }

        if (null !== $this->container) {
            return null;
        }

        // Tier 3: Global PATH
        /** @psalm-suppress ForbiddenCode — shell_exec is intentional for binary resolution; input is escaped */
        $globalBin = trim((string) shell_exec('command -v ' . escapeshellarg($basename) . ' 2>/dev/null'));
        if ('' !== $globalBin && is_executable($globalBin)) {
            return $globalBin;
        }

        return null;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function prepare(array $command, bool $inContainer, bool $interactive): array
    {
        return $inContainer && null !== $this->container
            ? $this->container->exec($command, $interactive)
            : $command;
    }

    /**
     * Read stdout and stderr concurrently until both reach EOF.
     *
     * Reading one pipe to EOF before the other deadlocks as soon as the child
     * fills the second pipe's buffer (64 KiB on Linux): the child blocks on
     * write while the parent blocks on read. Multiplexing with stream_select()
     * keeps both pipes flowing.
     *
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @return array{string, string}
     */
    private function drain($stdoutPipe, $stderrPipe): array
    {
        stream_set_blocking($stdoutPipe, false);
        stream_set_blocking($stderrPipe, false);

        $buffers = ['stdout' => '', 'stderr' => ''];
        $open = ['stdout' => $stdoutPipe, 'stderr' => $stderrPipe];

        while ([] !== $open) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (false === stream_select($read, $write, $except, 1)) {
                break;
            }

            foreach ($open as $name => $pipe) {
                $chunk = fread($pipe, 8192);

                if (\is_string($chunk) && '' !== $chunk) {
                    $buffers[$name] .= $chunk;
                }

                if (feof($pipe)) {
                    unset($open[$name]);
                }
            }
        }

        return [$buffers['stdout'], $buffers['stderr']];
    }
}
