<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Configuration;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;

/**
 * Minimal, dependency-free YAML emitter for generated Compose files.
 *
 * Supports exactly what `.kcode/docker/compose.yaml` needs: nested maps,
 * lists and scalars. Strings are emitted as JSON strings, which are valid
 * YAML double-quoted scalars, so no value can be misread as a number,
 * boolean or anchor.
 *
 * @since 1.2.0
 */
final class YamlWriter
{
    private const string INDENT = '  ';
    private const string PLAIN_KEY = '/^[A-Za-z0-9_.\/-]+$/';

    /** @param array<array-key, mixed> $data */
    public function dump(array $data): string
    {
        return $this->block($data, 0);
    }

    /** @param array<array-key, mixed> $data */
    private function block(array $data, int $depth): string
    {
        $padding = str_repeat(self::INDENT, $depth);
        $yaml = '';

        if (array_is_list($data)) {
            foreach ($data as $item) {
                $yaml .= \is_array($item) && [] !== $item
                    ? $padding . '-' . PHP_EOL . $this->block($item, $depth + 1)
                    : $padding . '- ' . $this->scalar($item) . PHP_EOL;
            }

            return $yaml;
        }

        foreach ($data as $key => $value) {
            $prefix = $padding . $this->key((string) $key) . ':';

            $yaml .= \is_array($value) && [] !== $value
                ? $prefix . PHP_EOL . $this->block($value, $depth + 1)
                : $prefix . ' ' . $this->scalar($value) . PHP_EOL;
        }

        return $yaml;
    }

    private function key(string $key): string
    {
        return 1 === preg_match(self::PLAIN_KEY, $key) ? $key : $this->quote($key);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            true === $value => 'true',
            false === $value => 'false',
            \is_int($value), \is_float($value) => (string) $value,
            \is_array($value) => '[]',
            \is_string($value) => $this->quote($value),
            default => $this->quote(get_debug_type($value)),
        };
    }

    private function quote(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
