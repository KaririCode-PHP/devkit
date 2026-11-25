<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Core;

/**
 * Rewrites Composer scripts that call removed QA binaries into kcode commands.
 *
 * Once the tools leave `require-dev`, `"test": "phpunit"` or
 * `"cs-check": "vendor/bin/php-cs-fixer fix --dry-run"` would fail. Each
 * shell segment (split on `&&` and `;`) whose binary is a known tool is
 * replaced; every other segment, `@script` reference and `@composer`
 * call is kept verbatim.
 *
 * | Binary                          | Replacement                          |
 * |---------------------------------|--------------------------------------|
 * | phpunit [args]                  | kcode test [args, minus -c/--configuration] |
 * | phpstan, psalm, phpinsights     | kcode analyse                        |
 * | php-cs-fixer (check/--dry-run)  | kcode cs:fix --check                 |
 * | php-cs-fixer (fix)              | kcode cs:fix                         |
 * | phpcs / phpcbf                  | kcode cs:fix --check / kcode cs:fix  |
 * | rector (--dry-run) / rector     | kcode rector / kcode rector --fix    |
 * | security-checker                | kcode security                       |
 * | infection, paratest [args]      | kcode run <binary> [args]            |
 *
 * @since 1.2.0
 */
final readonly class ComposerScriptRewriter
{
    private const array ANALYSERS = ['phpstan', 'psalm', 'phpinsights'];
    private const array PASSTHROUGH_BINARIES = ['infection', 'paratest'];

    /**
     * @param array<array-key, mixed> $scripts The composer.json "scripts" object
     * @return array<string, array{from: string|list<string>, to: string|list<string>}>
     */
    public function rewrites(array $scripts): array
    {
        $rewrites = [];

        foreach ($scripts as $name => $definition) {
            if (\is_string($definition)) {
                $rewritten = $this->rewriteLine($definition);

                if ($rewritten !== $definition) {
                    $rewrites[(string) $name] = ['from' => $definition, 'to' => $rewritten];
                }

                continue;
            }

            $lines = $this->stringList($definition);

            if (null === $lines) {
                continue; // not a script list (e.g. a nested object): leave it alone
            }

            $rewritten = $this->rewriteList($lines);

            if ($rewritten !== $lines) {
                $rewrites[(string) $name] = ['from' => $lines, 'to' => $rewritten];
            }
        }

        return $rewrites;
    }

    /** Rewrite one script line; returns it unchanged when no segment calls a known tool. */
    public function rewriteLine(string $line): string
    {
        $parts = preg_split('/(\s*(?:&&|;)\s*)/', $line, -1, \PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return $line;
        }

        $changed = false;

        foreach ($parts as $index => $part) {
            if (1 === $index % 2) {
                continue; // separator
            }

            $rewritten = $this->rewriteSegment($part);
            $changed = $changed || $rewritten !== $part;
            $parts[$index] = $rewritten;
        }

        return $changed ? implode('', $parts) : $line;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function rewriteList(array $lines): array
    {
        $rewritten = [];
        $previous = null;

        foreach ($lines as $line) {
            $line = $this->rewriteLine($line);

            // Two analysers in a row (phpstan, psalm) both become "kcode analyse": run it once.
            if ($line === $previous && str_starts_with($line, 'kcode ')) {
                continue;
            }

            $rewritten[] = $line;
            $previous = $line;
        }

        return $rewritten;
    }

    /** @return list<string>|null */
    private function stringList(mixed $definition): ?array
    {
        if (! \is_array($definition) || ! array_is_list($definition)) {
            return null;
        }

        $lines = [];

        foreach ($definition as $line) {
            if (! \is_string($line)) {
                return null;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function rewriteSegment(string $segment): string
    {
        $tokens = preg_split('/\s+/', trim($segment), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $tokens || [] === $tokens) {
            return $segment;
        }

        // Skip VAR=value prefixes and the PHP launcher (@php, php).
        $position = 0;

        while (isset($tokens[$position])
            && (1 === preg_match('/^[A-Za-z_]\w*=/', $tokens[$position]) || \in_array($tokens[$position], ['@php', 'php'], true))) {
            ++$position;
        }

        $binary = basename($tokens[$position] ?? '');
        $arguments = \array_slice($tokens, $position + 1);

        return match (true) {
            'phpunit' === $binary => $this->command('test', $this->withoutConfiguration($arguments)),
            \in_array($binary, self::ANALYSERS, true) => 'kcode analyse',
            'php-cs-fixer' === $binary => $this->isCheck($arguments) ? 'kcode cs:fix --check' : 'kcode cs:fix',
            'phpcs' === $binary => 'kcode cs:fix --check',
            'phpcbf' === $binary => 'kcode cs:fix',
            'rector' === $binary => \in_array('--dry-run', $arguments, true) ? 'kcode rector' : 'kcode rector --fix',
            'security-checker' === $binary => 'kcode security',
            \in_array($binary, self::PASSTHROUGH_BINARIES, true) => $this->command('run ' . $binary, $arguments),
            default => $segment,
        };
    }

    /** @param list<string> $arguments */
    private function command(string $command, array $arguments): string
    {
        return trim('kcode ' . $command . ' ' . implode(' ', $arguments));
    }

    /** @param list<string> $arguments */
    private function isCheck(array $arguments): bool
    {
        return 'check' === ($arguments[0] ?? null) || \in_array('--dry-run', $arguments, true);
    }

    /**
     * kcode passes its own --configuration; drop the script's.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    private function withoutConfiguration(array $arguments): array
    {
        $kept = [];
        $skipNext = false;

        foreach ($arguments as $argument) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ('-c' === $argument || '--configuration' === $argument) {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($argument, '--configuration=')) {
                continue;
            }

            $kept[] = $argument;
        }

        return $kept;
    }
}
