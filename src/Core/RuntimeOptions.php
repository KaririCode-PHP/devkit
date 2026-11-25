<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

/**
 * Global options that apply to every command, parsed before dispatch.
 *
 *   --docker      Run the command's tools inside the PHP container
 *   --no-docker   Run them on the host, even when devkit.php enables Docker
 *
 * Precedence: command-line flag › KCODE_DOCKER environment variable ›
 * devkit.php `docker.enabled`. Arguments after a literal `--` are never
 * interpreted, so `kcode exec -- php script.php --docker` passes the flag on.
 *
 * @since 1.2.0
 */
final readonly class RuntimeOptions
{
    private const string FLAG_DOCKER = '--docker';
    private const string FLAG_NO_DOCKER = '--no-docker';
    private const string END_OF_OPTIONS = '--';
    private const string ENVIRONMENT_VARIABLE = 'KCODE_DOCKER';

    /** @param list<string> $arguments Raw argv without the global flags */
    private function __construct(
        public array $arguments,
        public ?bool $docker,
    ) {
    }

    /**
     * @param list<string>                $argv        Raw $argv, script name first
     * @param array<array-key, mixed>     $environment Typically getenv()
     */
    public static function parse(array $argv, array $environment = []): self
    {
        $docker = self::fromEnvironment($environment[self::ENVIRONMENT_VARIABLE] ?? null);
        $arguments = [];
        $parsing = true;

        foreach ($argv as $position => $argument) {
            if ($parsing && $position > 0) {
                if (self::END_OF_OPTIONS === $argument) {
                    $parsing = false;
                } elseif (self::FLAG_DOCKER === $argument) {
                    $docker = true;

                    continue;
                } elseif (self::FLAG_NO_DOCKER === $argument) {
                    $docker = false;

                    continue;
                }
            }

            $arguments[] = $argument;
        }

        return new self($arguments, $docker);
    }

    /** Resolve the effective Docker mode against the project default (devkit.php). */
    public function useDocker(bool $projectDefault): bool
    {
        return $this->docker ?? $projectDefault;
    }

    private static function fromEnvironment(mixed $value): ?bool
    {
        if (! \is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
