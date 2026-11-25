<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;

use const PHP_EOL;

/**
 * Remembers what `.kcode/` was generated and installed from.
 *
 * Stored in `.kcode/state.json` (gitignored with the rest of `.kcode/`):
 *
 *   - `configs`: fingerprint of the devkit version, `composer.json` and
 *     `devkit.php` the generated configs were rendered from;
 *   - `tools`: fingerprint of `.kcode/composer.json` and `devkit.lock` the
 *     toolchain in `.kcode/vendor/` was installed from.
 *
 * A mismatch means the corresponding step must run again, which lets a
 * global `kcode` keep `.kcode/` current without an explicit `kcode init`.
 *
 * @since 1.2.0
 */
final readonly class ToolchainState
{
    public const string STATE_FILE = 'state.json';
    public const string LOCK_FILE = 'devkit.lock';

    private const string KEY_CONFIGS = 'configs';
    private const string KEY_TOOLS = 'tools';

    public function __construct(
        private string $devkitVersion,
    ) {
    }

    /** Fingerprint of the inputs the generated configs depend on. */
    public function configsFingerprint(ProjectContext $context): string
    {
        return sha1(implode("\0", [
            $this->devkitVersion,
            $this->read($context->rootPath('composer.json')),
            $this->read($context->rootPath('devkit.php')),
        ]));
    }

    /** Fingerprint of the inputs the installed toolchain depends on. */
    public function toolsFingerprint(ProjectContext $context): string
    {
        return sha1(implode("\0", [
            $this->read($context->configPath('composer.json')),
            $this->read($context->rootPath(self::LOCK_FILE)),
        ]));
    }

    public function configsCurrent(ProjectContext $context): bool
    {
        return $this->stored($context, self::KEY_CONFIGS) === $this->configsFingerprint($context);
    }

    /** Whether the last install used the current `.kcode/composer.json` and `devkit.lock`. */
    public function toolsInputsUnchanged(ProjectContext $context): bool
    {
        return $this->stored($context, self::KEY_TOOLS) === $this->toolsFingerprint($context);
    }

    /** Whether `.kcode/vendor/` exists and was installed from the current manifest and lock. */
    public function toolsCurrent(ProjectContext $context): bool
    {
        return is_file($context->configPath('vendor/autoload.php')) && $this->toolsInputsUnchanged($context);
    }

    public function recordConfigs(ProjectContext $context): void
    {
        $this->record($context, self::KEY_CONFIGS, $this->configsFingerprint($context));
    }

    public function recordTools(ProjectContext $context): void
    {
        $this->record($context, self::KEY_TOOLS, $this->toolsFingerprint($context));
    }

    private function stored(ProjectContext $context, string $key): ?string
    {
        $value = $this->load($context)[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    private function record(ProjectContext $context, string $key, string $fingerprint): void
    {
        if (! is_dir($context->devkitDir)) {
            return;
        }

        $state = $this->load($context);
        $state[$key] = $fingerprint;
        $state['devkit'] = $this->devkitVersion;
        ksort($state);

        file_put_contents(
            $context->configPath(self::STATE_FILE),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    /** @return array<array-key, mixed> */
    private function load(ProjectContext $context): array
    {
        $raw = $this->read($context->configPath(self::STATE_FILE));

        if ('' === $raw) {
            return [];
        }

        try {
            $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return \is_array($state) ? $state : [];
    }

    private function read(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return false === $content ? '' : $content;
    }
}
