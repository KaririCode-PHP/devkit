<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Unit\Configuration;

use KaririCode\Devkit\Configuration\DockerComposeGenerator;
use KaririCode\Devkit\Configuration\DockerfileGenerator;
use KaririCode\Devkit\Configuration\PhpIniGenerator;
use KaririCode\Devkit\Configuration\YamlWriter;
use KaririCode\Devkit\Core\ContainerRuntime;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\ValueObject\DockerSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DockerComposeGenerator::class)]
#[CoversClass(DockerfileGenerator::class)]
#[CoversClass(PhpIniGenerator::class)]
#[UsesClass(YamlWriter::class)]
#[UsesClass(ContainerRuntime::class)]
#[UsesClass(ProjectContext::class)]
#[UsesClass(DockerSettings::class)]
final class DockerGeneratorsTest extends TestCase
{
    /** @param array<string, mixed> $docker */
    private function makeContext(array $docker = [], string $projectName = 'kariricode/validator'): ProjectContext
    {
        return new ProjectContext(
            projectRoot: '/home/dev/kariricode/validator',
            projectName: $projectName,
            namespace: 'KaririCode\\Validator',
            phpVersion: '8.4',
            phpstanLevel: 9,
            psalmLevel: 3,
            sourceDirs: ['/home/dev/kariricode/validator/src'],
            testDirs: ['/home/dev/kariricode/validator/tests'],
            excludeDirs: [],
            testSuites: [],
            coverageExclude: [],
            csFixerRules: [],
            rectorSets: [],
            toolVersions: [],
            kaririCodeComponent: true,
            docker: DockerSettings::fromArray($docker, '8.4'),
        );
    }

    // ── Dockerfile ────────────────────────────────────────────────

    #[Test]
    public function dockerfileTargetsTheConfiguredPhpVersion(): void
    {
        $generator = new DockerfileGenerator();

        $this->assertSame('dockerfile', $generator->toolName());
        $this->assertSame('docker/Dockerfile', $generator->outputPath());
        $this->assertStringContainsString(
            'FROM php:8.5-cli-alpine',
            $generator->generate($this->makeContext(['php_version' => '8.5'])),
        );
    }

    #[Test]
    public function dockerfileInstallsDefaultExtensionsComposerAndGit(): void
    {
        $dockerfile = new DockerfileGenerator()->generate($this->makeContext());

        $this->assertStringContainsString('RUN install-php-extensions pcov xdebug intl zip', $dockerfile);
        $this->assertStringContainsString('COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer', $dockerfile);
        $this->assertStringContainsString('RUN apk add --no-cache git unzip', $dockerfile);
        $this->assertStringContainsString('ENV HOME=/tmp', $dockerfile);
    }

    #[Test]
    public function dockerfileAddsExtraPackagesWithoutDuplicates(): void
    {
        $dockerfile = new DockerfileGenerator()->generate($this->makeContext(['packages' => ['git', 'bash']]));

        $this->assertStringContainsString('RUN apk add --no-cache git unzip bash', $dockerfile);
    }

    #[Test]
    public function dockerfileSkipsTheExtensionInstallerWithoutExtensions(): void
    {
        $dockerfile = new DockerfileGenerator()->generate($this->makeContext(['extensions' => []]));

        $this->assertStringNotContainsString('install-php-extensions', $dockerfile);
    }

    // ── php.ini ───────────────────────────────────────────────────

    #[Test]
    public function phpIniKeepsXdebugOffByDefault(): void
    {
        $generator = new PhpIniGenerator();
        $ini = $generator->generate($this->makeContext());

        $this->assertSame('php-ini', $generator->toolName());
        $this->assertSame('docker/php.ini', $generator->outputPath());
        $this->assertStringContainsString('xdebug.mode = off', $ini);
        $this->assertStringContainsString('memory_limit = -1', $ini);
        $this->assertNotFalse(parse_ini_string($ini));
    }

    #[Test]
    public function phpIniAppliesOverridesAndQuotesUnsafeValues(): void
    {
        $ini = new PhpIniGenerator()->generate($this->makeContext(['ini' => [
            'memory_limit' => '512M',
            'error_reporting' => 'E_ALL & ~E_DEPRECATED',
            'highlight.comment' => '#FF8000; italic',
        ]]));

        $this->assertStringContainsString('memory_limit = 512M', $ini);
        $this->assertStringContainsString('error_reporting = E_ALL & ~E_DEPRECATED', $ini);
        $this->assertStringContainsString('highlight.comment = "#FF8000; italic"', $ini);
        $this->assertStringNotContainsString('memory_limit = -1', $ini);

        $parsed = parse_ini_string($ini);
        $this->assertIsArray($parsed);
        $this->assertSame('#FF8000; italic', $parsed['highlight.comment']);
    }

    // ── compose.yaml ──────────────────────────────────────────────

    #[Test]
    public function composeMountsTheProjectAtItsHostPath(): void
    {
        $generator = new DockerComposeGenerator();
        $compose = $generator->generate($this->makeContext());

        $this->assertSame('docker-compose', $generator->toolName());
        $this->assertSame('docker/compose.yaml', $generator->outputPath());
        $this->assertStringContainsString('name: "kcode-kariricode-validator"', $compose);
        $this->assertStringContainsString('working_dir: "/home/dev/kariricode/validator"', $compose);
        $this->assertStringContainsString('- "/home/dev/kariricode/validator:/home/dev/kariricode/validator"', $compose);
        $this->assertStringContainsString('- "./php.ini:/usr/local/etc/php/conf.d/zz-kcode.ini:ro"', $compose);
        $this->assertStringContainsString('KCODE_CONTAINER: "1"', $compose);
    }

    #[Test]
    public function composeBuildsAHashTaggedImageByDefault(): void
    {
        $generator = new DockerComposeGenerator();
        $context = $this->makeContext();
        $compose = $generator->generate($context);

        $this->assertMatchesRegularExpression('/^kcode-php:8\.4-[0-9a-f]{12}$/', $generator->imageTag($context));
        $this->assertStringContainsString("image: \"{$generator->imageTag($context)}\"", $compose);
        $this->assertStringContainsString('dockerfile: "Dockerfile"', $compose);
        $this->assertStringContainsString('pull_policy: "missing"', $compose);
    }

    #[Test]
    public function composeImageTagChangesWithTheDockerfile(): void
    {
        $generator = new DockerComposeGenerator();

        $this->assertNotSame(
            $generator->imageTag($this->makeContext()),
            $generator->imageTag($this->makeContext(['extensions' => ['pcov', 'redis']])),
        );
        $this->assertSame(
            $generator->imageTag($this->makeContext()),
            $generator->imageTag($this->makeContext([], 'kariricode/sanitizer')),
        );
    }

    #[Test]
    public function composeUsesAPrebuiltImageWithoutBuildSection(): void
    {
        $compose = new DockerComposeGenerator()->generate(
            $this->makeContext(['image' => 'kariricode/php-api-stack:dev']),
        );

        $this->assertStringContainsString('image: "kariricode/php-api-stack:dev"', $compose);
        $this->assertStringNotContainsString('build:', $compose);
    }

    #[Test]
    public function composeAddsSidecarsPortsAndEnvironment(): void
    {
        $compose = new DockerComposeGenerator()->generate($this->makeContext([
            'ports' => ['8080:8080'],
            'environment' => ['APP_ENV' => 'testing'],
            'services' => [
                'redis' => 'redis:7-alpine',
                'mysql' => ['image' => 'mysql:8.4', 'environment' => ['MYSQL_ROOT_PASSWORD' => 'root']],
            ],
        ]));

        $this->assertStringContainsString("ports:\n      - \"8080:8080\"", $compose);
        $this->assertStringContainsString('APP_ENV: "testing"', $compose);
        $this->assertStringContainsString("depends_on:\n      - \"redis\"\n      - \"mysql\"", $compose);
        $this->assertStringContainsString("redis:\n    image: \"redis:7-alpine\"", $compose);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD: "root"', $compose);
    }

    #[Test]
    public function composeMergesRawServiceKeys(): void
    {
        $compose = new DockerComposeGenerator()->generate($this->makeContext(['service' => [
            'network_mode' => 'host',
            'volumes' => ['/opt/cache:/opt/cache'],
            'environment' => ['HOME' => '/home/dev'],
        ]]));

        $this->assertStringContainsString('network_mode: "host"', $compose);
        // Lists append: the project mount stays first.
        $this->assertStringContainsString(
            "- \"/home/dev/kariricode/validator:/home/dev/kariricode/validator\"\n"
            . "      - \"./php.ini:/usr/local/etc/php/conf.d/zz-kcode.ini:ro\"\n"
            . "      - \"/opt/cache:/opt/cache\"",
            $compose,
        );
        // Maps merge: overrides win.
        $this->assertStringContainsString('HOME: "/home/dev"', $compose);
        $this->assertStringContainsString('COMPOSER_HOME: "/tmp/composer"', $compose);
    }
}
