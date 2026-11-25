<?php

declare(strict_types=1);

namespace KaririCode\Devkit\Tests\Integration;

use KaririCode\Devkit\Command\RunCommand;
use KaririCode\Devkit\Tests\Integration\Support\KcodeTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RunCommand::class)]
final class RunCommandTest extends KcodeTestCase
{
    #[Test]
    public function runsAToolchainBinaryWithItsArguments(): void
    {
        $result = $this->kcode(['run', 'infection', '--threads=max', '--show-mutations']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('infection --threads=max --show-mutations', $result['stdout']);
    }

    #[Test]
    public function fallsBackToTheProjectVendorBin(): void
    {
        $this->kcode(['init']);
        mkdir($this->projectRoot . '/vendor/bin', 0o777, true);
        file_put_contents($this->projectRoot . '/vendor/autoload.php', '<?php');
        file_put_contents($this->projectRoot . '/vendor/bin/paratest', "#!/bin/sh\necho \"paratest \$*\"\n");
        chmod($this->projectRoot . '/vendor/bin/paratest', 0o755);

        $result = $this->kcode(['run', '--', 'paratest', '--processes=4']);

        $this->assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('paratest --processes=4', $result['stdout']);
    }

    #[Test]
    public function reportsAMissingBinary(): void
    {
        $result = $this->kcode(['run', 'deptrac']);

        $this->assertSame(127, $result['exitCode']);
        $this->assertStringContainsString('Binary "deptrac" not found', $result['stderr']);
        $this->assertStringContainsString("devkit.php → 'tools'", $result['stdout']);
    }

    #[Test]
    public function rejectsPathsAndMissingNames(): void
    {
        $this->assertSame(1, $this->kcode(['run'])['exitCode']);
        $this->assertSame(1, $this->kcode(['run', '../../bin/sh'])['exitCode']);
    }
}
