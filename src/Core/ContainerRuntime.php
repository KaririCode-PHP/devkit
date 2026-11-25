<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

/**
 * Builds `docker compose` command lines for a project's PHP container.
 *
 * The container is defined by the generated `.kcode/docker/compose.yaml`.
 * This class only assembles argument vectors; ProcessExecutor runs them.
 * Keeping it pure makes every command testable without Docker.
 *
 * Commands executed inside the container run:
 *   - as the host user (`--user uid:gid`), so files written to the bind
 *     mount keep the developer's ownership;
 *   - from the project root, which is mounted at the same absolute path;
 *   - with XDEBUG_* variables forwarded, so `XDEBUG_MODE=debug kcode test
 *     --docker` enables Xdebug for one run.
 *
 * @since 1.2.0
 */
final readonly class ContainerRuntime
{
    /** Compose service that runs PHP tools. */
    public const string SERVICE = 'php';

    /** Location of the generated Compose file inside `.kcode/`. */
    public const string COMPOSE_FILE = 'docker/compose.yaml';

    private const array FORWARDED_VARIABLES = ['XDEBUG_MODE', 'XDEBUG_CONFIG', 'XDEBUG_SESSION'];

    /**
     * @param string|null                $user        "uid:gid" for exec; null detects the host user, '' disables
     * @param array<string, string>|null $environment Host environment; null reads getenv()
     */
    public function __construct(
        private ProjectContext $context,
        private string $dockerBinary = 'docker',
        private ?string $user = null,
        private ?array $environment = null,
    ) {
    }

    /** Compose project name: "kcode-" followed by the slugged package name. */
    public static function projectNameFor(ProjectContext $context): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($context->projectName)), '-');

        return 'kcode-' . ('' !== $slug ? $slug : 'project');
    }

    public function projectName(): string
    {
        return self::projectNameFor($this->context);
    }

    public function composeFile(): string
    {
        return $this->context->configPath(self::COMPOSE_FILE);
    }

    /** Whether `.kcode/docker/compose.yaml` has been generated. */
    public function isConfigured(): bool
    {
        return is_file($this->composeFile());
    }

    /** @return list<string> */
    public function compose(string ...$arguments): array
    {
        return [
            $this->dockerBinary,
            'compose',
            '--file',
            $this->composeFile(),
            '--project-name',
            $this->projectName(),
            ...array_values($arguments),
        ];
    }

    /**
     * Wrap a command so it runs inside the PHP service.
     *
     * @param list<string> $command
     * @param bool         $interactive Allocate a TTY (shells, prompts) instead of `-T`
     * @return list<string>
     */
    public function exec(array $command, bool $interactive = false): array
    {
        $options = $interactive ? [] : ['-T'];
        $user = $this->user ?? $this->hostUser();

        if ('' !== $user) {
            $options[] = '--user';
            $options[] = $user;
        }

        foreach ($this->forwardedEnvironment() as $name => $value) {
            $options[] = '--env';
            $options[] = "{$name}={$value}";
        }

        return $this->compose(
            'exec',
            ...$options,
            ...['--workdir', $this->context->projectRoot, self::SERVICE],
            ...$command,
        );
    }

    /** @return list<string> */
    public function up(bool $build = false): array
    {
        return $build
            ? $this->compose('up', '--detach', '--wait', '--build')
            : $this->compose('up', '--detach', '--wait');
    }

    /** @return list<string> */
    public function down(bool $volumes = false): array
    {
        return $volumes
            ? $this->compose('down', '--remove-orphans', '--volumes')
            : $this->compose('down', '--remove-orphans');
    }

    /**
     * Prints the container id when the PHP service is running, nothing otherwise.
     *
     * @return list<string>
     */
    public function status(): array
    {
        return $this->compose('ps', '--status', 'running', '--quiet', self::SERVICE);
    }

    /** "uid:gid" of the host user; empty for root or when POSIX functions are unavailable. */
    private function hostUser(): string
    {
        if (! \function_exists('posix_getuid') || ! \function_exists('posix_getgid')) {
            return '';
        }

        $uid = posix_getuid();

        return 0 === $uid ? '' : $uid . ':' . posix_getgid();
    }

    /** @return array<string, string> */
    private function forwardedEnvironment(): array
    {
        $environment = $this->environment ?? getenv();
        $forwarded = [];

        foreach (self::FORWARDED_VARIABLES as $name) {
            $value = $environment[$name] ?? null;

            if (\is_string($value) && '' !== $value) {
                $forwarded[$name] = $value;
            }
        }

        return $forwarded;
    }
}
