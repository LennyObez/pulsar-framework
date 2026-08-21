<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DataPurgeCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\Tests\Unit\DataProtection\Support\RecordingPurger;

use function str_contains;

#[CoversClass(DataPurgeCommand::class)]
final class DataPurgeCommandTest extends TestCase
{
    #[Test]
    public function isNamedForWhatItDoes(): void
    {
        $command = new DataPurgeCommand($this->orchestrator(new RecordingPurger([])));

        self::assertSame('data:purge', $command->name);
    }

    #[Test]
    public function purgingFromTheConsoleRemovesTheExpiredRecords(): void
    {
        // Before this command existed the orchestrator was built at boot and
        // resolved by nothing, so no retention period in config/data_protection.php
        // was ever applied. The property is that the records are gone.
        $purger = new RecordingPurger(['log-1', 'log-2']);
        $output = new BufferedOutput();

        $exit = new DataPurgeCommand($this->orchestrator($purger))
            ->execute(new ArrayInput(arguments: []), $output);

        $text = $output->fetch();

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertSame(['log-1', 'log-2'], $purger->purged);
        self::assertSame([], $purger->remaining());
        self::assertTrue(str_contains($text, '2 record(s) purged'), $text);
    }

    #[Test]
    public function dryRunCountsWithoutDeleting(): void
    {
        $purger = new RecordingPurger(['log-1', 'log-2', 'log-3']);
        $output = new BufferedOutput();

        $exit = new DataPurgeCommand($this->orchestrator($purger))
            ->execute(new ArrayInput(arguments: [], options: ['dry-run' => true]), $output);

        $text = $output->fetch();

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertSame([], $purger->purged);
        self::assertSame(['log-1', 'log-2', 'log-3'], $purger->remaining());
        self::assertTrue(str_contains($text, 'eligible'), $text);
    }

    #[Test]
    public function warnsWhenNoCategoryCanBePurgedAtAll(): void
    {
        $orchestrator = new DataPurgeOrchestrator(
            purgers: [],
            policies: ['audit_logs' => new DefaultRetentionPolicy('audit_logs', 2555)],
            config: new DataProtectionConfig(),
        );

        $output = new BufferedOutput();
        $exit = new DataPurgeCommand($orchestrator)->execute(new ArrayInput(arguments: []), $output);
        $text = $output->fetch();

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertTrue(str_contains($text, 'nothing'), $text);
    }

    private function orchestrator(RecordingPurger $purger): DataPurgeOrchestrator
    {
        return new DataPurgeOrchestrator(
            purgers: ['audit_logs' => $purger],
            policies: ['audit_logs' => new DefaultRetentionPolicy('audit_logs', 2555, 'SOX Section 802')],
            config: new DataProtectionConfig(),
        );
    }
}
