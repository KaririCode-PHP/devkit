<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Runner;

use Closure;

use const FILTER_VALIDATE_BOOL;

use KaririCode\Devkit\Core\ProcessExecutor;
use KaririCode\Devkit\Core\ProjectContext;
use KaririCode\Devkit\ValueObject\ToolResult;
use Override;

/**
 * Runs PHPUnit with the generated `.kcode/phpunit.xml.dist`.
 *
 * The generated configuration declares coverage reports. PHPUnit refuses
 * to run any test when such a report is configured and no coverage driver
 * is loaded, so when the PHP that runs the tests has neither PCOV nor
 * Xdebug in coverage mode, the runner adds `--no-coverage` — unless the
 * caller asked for coverage explicitly. In container mode the probe runs
 * inside the container, so prebuilt images without PCOV still work.
 *
 * @since 1.0.0
 */
final class PhpUnitRunner extends AbstractToolRunner
{
    /** Same check as hostHasCoverageDriver(), evaluated by the container's PHP. */
    private const string CONTAINER_PROBE = 'echo (extension_loaded("pcov") && filter_var(ini_get("pcov.enabled"), FILTER_VALIDATE_BOOL))'
        . ' || (extension_loaded("xdebug") && str_contains((string) (getenv("XDEBUG_MODE") ?: ini_get("xdebug.mode")), "coverage"))'
        . ' ? "1" : "0";';

    /**
     * @param (Closure(): bool)|null $coverageDriverProbe Defaults to the host check, or to the
     *                                                    container check in Docker mode
     */
    public function __construct(
        ProcessExecutor $executor,
        ProjectContext $context,
        private readonly ?Closure $coverageDriverProbe = null,
    ) {
        parent::__construct($executor, $context);
    }

    /** Whether the PHP running kcode can collect code coverage (PCOV, or Xdebug in coverage mode). */
    public static function hostHasCoverageDriver(): bool
    {
        if (\extension_loaded('pcov') && filter_var(\ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if (! \extension_loaded('xdebug')) {
            return false;
        }

        $mode = getenv('XDEBUG_MODE');
        $mode = \is_string($mode) && '' !== $mode ? $mode : (string) \ini_get('xdebug.mode');

        return str_contains($mode, 'coverage');
    }

    #[Override]
    public function toolName(): string
    {
        return 'phpunit';
    }

    /** @param list<string> $arguments */
    #[Override]
    public function run(array $arguments = []): ToolResult
    {
        if ($this->mustDisableCoverage($arguments)) {
            $arguments[] = '--no-coverage';
        }

        return parent::run($arguments);
    }

    #[Override]
    protected function vendorBin(): string
    {
        return 'vendor/bin/phpunit';
    }

    #[Override]
    protected function defaultArguments(): array
    {
        return [
            '--configuration',
            $this->context->configPath('phpunit.xml.dist'),
            '--testdox',
            '--colors=always',
        ];
    }

    /** @param list<string> $arguments */
    private function mustDisableCoverage(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--coverage') || '--no-coverage' === $argument) {
                return false;
            }
        }

        if (null !== $this->coverageDriverProbe) {
            return ! ($this->coverageDriverProbe)();
        }

        return ! ($this->executor->isContainerized() ? $this->containerHasCoverageDriver() : self::hostHasCoverageDriver());
    }

    private function containerHasCoverageDriver(): bool
    {
        $probe = $this->executor->execute('php', ['php', '-r', self::CONTAINER_PROBE]);

        return $probe->success && '1' === trim($probe->stdout);
    }
}
