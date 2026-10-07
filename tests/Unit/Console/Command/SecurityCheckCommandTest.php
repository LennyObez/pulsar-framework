<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SecurityCheckCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Security\Posture\SecurityPostureItem;
use Pulsar\Security\Posture\SecurityPostureReport;

#[CoversClass(SecurityCheckCommand::class)]
final class SecurityCheckCommandTest extends TestCase
{
    #[Test]
    public function nameAndDescription(): void
    {
        $command = new SecurityCheckCommand(new SecurityPostureReport([]));

        self::assertSame('security:check', $command->name);
        self::assertStringContainsString('posture', $command->description);
    }

    #[Test]
    public function okReportExitsSuccessfully(): void
    {
        $report = new SecurityPostureReport([SecurityPostureItem::ok('csrf_protection', 'CSRF enabled')]);
        $command = new SecurityCheckCommand($report);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('security:check'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('OK', $output->buffer);
    }

    #[Test]
    public function failingReportExitsWithError(): void
    {
        $report = new SecurityPostureReport([
            SecurityPostureItem::fail('master_key', 'PULSAR_MASTER_KEY not set', 'Set a 32-byte key'),
        ]);
        $command = new SecurityCheckCommand($report);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('security:check'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('FAIL', $output->buffer);
        self::assertStringContainsString('Set a 32-byte key', $output->buffer);
    }

    #[Test]
    public function degradedReportWarnsButExitsSuccessfully(): void
    {
        $report = new SecurityPostureReport([
            SecurityPostureItem::degraded('hsts', 'max-age too low', 'Raise hsts.max_age'),
        ]);
        $command = new SecurityCheckCommand($report);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('security:check'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('WARN', $output->buffer);
    }
}
