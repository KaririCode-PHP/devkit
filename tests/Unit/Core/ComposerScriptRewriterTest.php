<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Core;

use KaririCode\Devkit\Core\ComposerScriptRewriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerScriptRewriter::class)]
final class ComposerScriptRewriterTest extends TestCase
{
    private ComposerScriptRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new ComposerScriptRewriter();
    }

    /** @return iterable<string, array{string, string}> */
    public static function lines(): iterable
    {
        yield 'bare phpunit' => ['phpunit', 'kcode test'];
        yield 'phpunit keeps its arguments' => ['phpunit --testsuite=Unit,Integration', 'kcode test --testsuite=Unit,Integration'];
        yield 'phpunit through vendor/bin' => ['./vendor/bin/phpunit --testdox --colors=always tests', 'kcode test --testdox --colors=always tests'];
        yield 'phpunit with @php and env prefix' => ['XDEBUG_MODE=coverage @php vendor/bin/phpunit --group=fail-closed', 'kcode test --group=fail-closed'];
        yield 'phpunit drops -c' => ['phpunit -c phpunit.xml.dist --stop-on-failure', 'kcode test --stop-on-failure'];
        yield 'phpunit drops --configuration=' => ['phpunit --configuration=phpunit.xml', 'kcode test'];
        yield 'phpstan' => ['phpstan analyse --no-progress --memory-limit=1G', 'kcode analyse'];
        yield 'psalm' => ['psalm --no-cache --show-info=false', 'kcode analyse'];
        yield 'phpinsights' => ['vendor/bin/phpinsights --no-interaction', 'kcode analyse'];
        yield 'cs-fixer dry run' => ['php-cs-fixer fix --dry-run --diff', 'kcode cs:fix --check'];
        yield 'cs-fixer check' => ['vendor/bin/php-cs-fixer check', 'kcode cs:fix --check'];
        yield 'cs-fixer fix' => ['php-cs-fixer fix', 'kcode cs:fix'];
        yield 'phpcs' => ['vendor/bin/phpcs --standard=phpcs.xml', 'kcode cs:fix --check'];
        yield 'phpcbf' => ['phpcbf src', 'kcode cs:fix'];
        yield 'rector dry run' => ['rector process --dry-run', 'kcode rector'];
        yield 'rector apply' => ['rector process', 'kcode rector --fix'];
        yield 'security-checker' => ['./vendor/bin/security-checker security:check', 'kcode security'];
        yield 'infection' => ['infection --threads=max --show-mutations', 'kcode run infection --threads=max --show-mutations'];
        yield 'paratest' => ['vendor/bin/paratest --processes=4', 'kcode run paratest --processes=4'];
        yield 'chained segments' => ['phpstan analyse && phpunit', 'kcode analyse && kcode test'];
        yield 'semicolon chain keeps other commands' => ['echo start; phpunit; echo done', 'echo start; kcode test; echo done'];
    }

    #[Test]
    #[DataProvider('lines')]
    public function rewritesToolInvocations(string $line, string $expected): void
    {
        $this->assertSame($expected, $this->rewriter->rewriteLine($line));
    }

    /** @return iterable<string, array{string}> */
    public static function untouchedLines(): iterable
    {
        yield 'script reference' => ['@phpstan'];
        yield 'composer call' => ['@composer audit --locked'];
        yield 'project tool' => ['@php tools/complexity.php src'];
        yield 'already kcode' => ['kcode test --coverage'];
        yield 'unrelated binary' => ['php-parse src/Foo.php'];
    }

    #[Test]
    #[DataProvider('untouchedLines')]
    public function leavesOtherCommandsUnchanged(string $line): void
    {
        $this->assertSame($line, $this->rewriter->rewriteLine($line));
    }

    #[Test]
    public function rewritesReportOnlyChangedScripts(): void
    {
        $rewrites = $this->rewriter->rewrites([
            'test' => 'phpunit --testsuite=Unit',
            'cs-check' => 'php-cs-fixer fix --dry-run --diff',
            'complexity' => '@php tools/complexity.php src',
            'quality' => ['@cs-check', '@test'],
            'post-install-cmd' => ['@composer dump-autoload'],
        ]);

        $this->assertSame([
            'test' => ['from' => 'phpunit --testsuite=Unit', 'to' => 'kcode test --testsuite=Unit'],
            'cs-check' => ['from' => 'php-cs-fixer fix --dry-run --diff', 'to' => 'kcode cs:fix --check'],
        ], $rewrites);
    }

    #[Test]
    public function rewritesScriptListsAndCollapsesRepeatedAnalysers(): void
    {
        $rewrites = $this->rewriter->rewrites([
            'check' => ['phpstan analyse', 'psalm', '@test', 'phpunit --group=fail-closed'],
        ]);

        $this->assertSame(
            ['kcode analyse', '@test', 'kcode test --group=fail-closed'],
            $rewrites['check']['to'],
        );
        $this->assertSame(['phpstan analyse', 'psalm', '@test', 'phpunit --group=fail-closed'], $rewrites['check']['from']);
    }

    #[Test]
    public function ignoresDefinitionsThatAreNotScripts(): void
    {
        $this->assertSame([], $this->rewriter->rewrites([
            'nested' => ['key' => 'phpunit'],
            'mixed' => ['phpunit', 42],
            'number' => 7,
        ]));
    }
}
