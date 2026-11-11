<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyHarnessGuardTest extends TestCase
{
    public static function refusedArguments(): array
    {
        return [
            [[]],
            [['--database=sivera', '--allow-staging-writes']],
            [['--database=simpel-dev']],
        ];
    }

    #[DataProvider('refusedArguments')]
    public function test_harness_refuses_to_boot_without_explicit_staging_target_and_write_opt_in(array $arguments): void
    {
        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/sqlserver-workflow.php'), ...$arguments]);
        $process->setTimeout(10)->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Explicit --database=simpel-dev --allow-staging-writes required.', $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
    }
}
