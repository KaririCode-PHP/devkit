<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

use Throwable;

/**
 * Adds packages to the `tools` key of `devkit.php` and enables `root_configs`.
 *
 * Used by `kcode migrate` to move test-support libraries (Mockery,
 * Infection, ParaTest, ...) out of `require-dev` and into the `.kcode/`
 * toolchain. PHPUnit autoloads `.kcode/vendor/`, so tests keep finding
 * those classes.
 *
 * `devkit.php` is PHP, not data, so edits are conservative:
 *   - no file: a minimal one is written;
 *   - a file without an active `tools` key: the key is inserted before the
 *     closing `];` of the returned array;
 *   - a file that already has `tools`: nothing is written unless every
 *     package is already listed — the caller asks the user to add them.
 *
 * @since 1.2.0
 */
final readonly class DevkitConfigWriter
{
    private const string CONFIG_FILE = 'devkit.php';

    /**
     * @param array<string, string> $packages Package name → version constraint
     * @return bool True when devkit.php lists every package afterwards
     */
    public function addTools(string $projectRoot, array $packages): bool
    {
        if ([] === $packages) {
            return true;
        }

        $path = $projectRoot . DIRECTORY_SEPARATOR . self::CONFIG_FILE;

        if (! is_file($path)) {
            return false !== file_put_contents($path, $this->newFile($this->toolsEntry($packages)));
        }

        $current = $this->load($path);

        if (null === $current) {
            return false;
        }

        if (\array_key_exists('tools', $current)) {
            $tools = \is_array($current['tools']) ? $current['tools'] : [];

            return array_all(
                array_keys($packages),
                static fn (string $package): bool => \array_key_exists($package, $tools),
            );
        }

        return $this->insert($path, $this->toolsEntry($packages));
    }

    /**
     * Set `'root_configs' => true`, so committed root tool configs win over
     * the generated ones (used by `kcode migrate --keep-configs`).
     *
     * @return bool True when devkit.php enables root configs afterwards
     *
     * @since 1.2.1
     */
    public function enableRootConfigs(string $projectRoot): bool
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . self::CONFIG_FILE;
        $entry = '    // ── Root tool configs win over .kcode/ (KaririCode Spec V4.3.3 §31) ──' . PHP_EOL
            . "    'root_configs' => true," . PHP_EOL;

        if (! is_file($path)) {
            return false !== file_put_contents($path, $this->newFile($entry));
        }

        $current = $this->load($path);

        if (null === $current) {
            return false;
        }

        if (\array_key_exists('root_configs', $current)) {
            return true === $current['root_configs'];
        }

        return $this->insert($path, $entry);
    }

    /** Insert an entry before the closing `];` of the returned array. */
    private function insert(string $path, string $entry): bool
    {
        $source = (string) file_get_contents($path);
        $updated = preg_replace('/\n\];\s*$/', PHP_EOL . $entry . '];' . PHP_EOL, $source, 1, $count);

        if (1 !== $count || ! \is_string($updated)) {
            return false;
        }

        return false !== file_put_contents($path, $updated);
    }

    private function newFile(string $entries): string
    {
        return '<?php' . PHP_EOL
            . PHP_EOL
            . 'declare(strict_types=1);' . PHP_EOL
            . PHP_EOL
            . '/**' . PHP_EOL
            . ' * KaririCode Devkit — Project Overrides' . PHP_EOL
            . ' *' . PHP_EOL
            . ' * Written by `kcode migrate`. Run `kcode init --config` for a copy of' . PHP_EOL
            . ' * every supported key, or see https://github.com/KaririCode-PHP/devkit.' . PHP_EOL
            . ' */' . PHP_EOL
            . PHP_EOL
            . 'return [' . PHP_EOL
            . $entries
            . '];' . PHP_EOL;
    }

    /** @param array<string, string> $packages */
    private function toolsEntry(array $packages): string
    {
        ksort($packages);

        $entry = '    // ── Toolchain extras (installed into .kcode/vendor/) ───────' . PHP_EOL
            . "    'tools' => [" . PHP_EOL;

        foreach ($packages as $package => $constraint) {
            $entry .= \sprintf('        %s => %s,', var_export($package, true), var_export($constraint, true)) . PHP_EOL;
        }

        return $entry . '    ],' . PHP_EOL;
    }

    /** @return array<array-key, mixed>|null */
    private function load(string $path): ?array
    {
        try {
            $loaded = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable) {
            return null;
        }

        return \is_array($loaded) ? $loaded : null;
    }
}
