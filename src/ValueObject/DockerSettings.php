<?php

declare(strict_types=1);

namespace KaririCode\Devkit\ValueObject;

use KaririCode\Devkit\Exception\ConfigurationException;

/**
 * Immutable container-runtime settings resolved from `devkit.php` → `docker`.
 *
 * Replaces the per-component `.docker/`, `docker-compose.yml`, `.env.example`
 * and Makefile scaffolding: `kcode up` renders `.kcode/docker/` from these
 * values, so every component shares one container definition.
 *
 * @since 1.2.0
 */
final readonly class DockerSettings
{
    /** PHP extensions baked into the generated image (installed by install-php-extensions). */
    public const array DEFAULT_EXTENSIONS = ['pcov', 'xdebug', 'intl', 'zip'];

    private const string KEY_PREFIX = 'docker.';

    /**
     * @param list<string>                               $extensions  install-php-extensions arguments
     * @param list<string>                               $packages    Extra Alpine packages for the image
     * @param array<string, string>                      $ini         php.ini directives (merged over defaults)
     * @param array<string, string>                      $environment Extra environment for the php service
     * @param list<string>                               $ports       Port mappings, e.g. "8080:8080"
     * @param array<string, string|array<string, mixed>> $services    Sidecars: name → image or Compose service
     * @param array<string, mixed>                       $service     Raw Compose keys merged into the php service
     */
    public function __construct(
        public bool $enabled = false,
        public string $phpVersion = '8.4',
        public ?string $image = null,
        public array $extensions = self::DEFAULT_EXTENSIONS,
        public array $packages = [],
        public array $ini = [],
        public array $environment = [],
        public array $ports = [],
        public array $services = [],
        public array $service = [],
    ) {
    }

    /**
     * Build settings from the raw `devkit.php` → `docker` array.
     *
     * @param array<array-key, mixed> $settings
     *
     * @throws ConfigurationException When a key holds a value of the wrong type.
     */
    public static function fromArray(array $settings, string $defaultPhpVersion): self
    {
        return new self(
            enabled: self::boolean($settings, 'enabled', false),
            phpVersion: self::string($settings, 'php_version') ?? $defaultPhpVersion,
            image: self::string($settings, 'image'),
            extensions: self::stringList($settings, 'extensions') ?? self::DEFAULT_EXTENSIONS,
            packages: self::stringList($settings, 'packages') ?? [],
            ini: self::stringMap($settings, 'ini'),
            environment: self::stringMap($settings, 'environment'),
            ports: self::stringList($settings, 'ports') ?? [],
            services: self::services($settings),
            service: self::map($settings, 'service'),
        );
    }

    /** Whether `kcode up` builds the image locally (no prebuilt image configured). */
    public function buildsImage(): bool
    {
        return null === $this->image;
    }

    // ── Validation helpers ────────────────────────────────────────

    /** @param array<array-key, mixed> $settings */
    private static function boolean(array $settings, string $key, bool $default): bool
    {
        $value = $settings[$key] ?? $default;

        if (! \is_bool($value)) {
            throw ConfigurationException::invalidOverride(self::KEY_PREFIX . $key, 'Expected boolean.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $settings */
    private static function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (! \is_string($value) || '' === trim($value)) {
            throw ConfigurationException::invalidOverride(self::KEY_PREFIX . $key, 'Expected a non-empty string.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return list<string>|null
     */
    private static function stringList(array $settings, string $key): ?array
    {
        $value = $settings[$key] ?? null;

        if (null === $value) {
            return null;
        }

        // array_all() passes ($value, $key): wrap is_string() so it receives a single argument.
        if (! \is_array($value) || ! array_is_list($value) || ! array_all($value, static fn (mixed $item): bool => \is_string($item))) {
            throw ConfigurationException::invalidOverride(self::KEY_PREFIX . $key, 'Expected a list of strings.');
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return array<string, string>
     */
    private static function stringMap(array $settings, string $key): array
    {
        $map = [];

        foreach (self::map($settings, $key) as $name => $value) {
            if (\is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (! \is_string($value) && ! \is_int($value) && ! \is_float($value)) {
                throw ConfigurationException::invalidOverride(
                    self::KEY_PREFIX . $key . '.' . $name,
                    'Expected a scalar value.',
                );
            }

            $map[$name] = (string) $value;
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return array<string, mixed>
     */
    private static function map(array $settings, string $key): array
    {
        $value = $settings[$key] ?? [];

        if (! \is_array($value) || ([] !== $value && array_is_list($value))) {
            throw ConfigurationException::invalidOverride(self::KEY_PREFIX . $key, 'Expected an associative array.');
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return array<string, string|array<string, mixed>>
     */
    private static function services(array $settings): array
    {
        $services = [];

        foreach (self::map($settings, 'services') as $name => $definition) {
            if (\is_string($definition)) {
                $services[$name] = $definition;

                continue;
            }

            if (! \is_array($definition) || array_is_list($definition)) {
                throw ConfigurationException::invalidOverride(
                    self::KEY_PREFIX . 'services.' . $name,
                    'Expected an image name or a Compose service array.',
                );
            }

            $service = [];

            foreach ($definition as $option => $item) {
                $service[(string) $option] = $item;
            }

            $services[$name] = $service;
        }

        return $services;
    }
}
