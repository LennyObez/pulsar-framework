<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Compliance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Compliance\SoxLogFormatter;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(SoxLogFormatter::class)]
final class SoxLogFormatterTest extends TestCase
{
    private SoxLogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SoxLogFormatter();
    }

    #[Test]
    public function addsSoxControlledFlag(): void
    {
        $entry = $this->createEntry(['action' => 'ledger_update']);

        $result = $this->formatter->format($entry);

        self::assertTrue($result->context['sox_controlled']);
    }

    #[Test]
    public function classifiesFinancialData(): void
    {
        $entry = $this->createEntry(['amount' => 1000.00, 'currency' => 'USD']);

        $result = $this->formatter->format($entry);

        self::assertSame('financial', $result->context['data_classification']);
    }

    #[Test]
    public function classifiesBalanceAsFinancial(): void
    {
        $entry = $this->createEntry(['balance' => 5000.00]);

        $result = $this->formatter->format($entry);

        self::assertSame('financial', $result->context['data_classification']);
    }

    #[Test]
    public function classifiesRevenueAsFinancial(): void
    {
        $entry = $this->createEntry(['revenue' => 10000.00]);

        $result = $this->formatter->format($entry);

        self::assertSame('financial', $result->context['data_classification']);
    }

    #[Test]
    public function classifiesChangeRecordWhenSnapshotsPresent(): void
    {
        $entry = $this->createEntry([
            'before' => ['status' => 'draft'],
            'after' => ['status' => 'approved'],
        ]);

        $result = $this->formatter->format($entry);

        self::assertSame('change_record', $result->context['data_classification']);
    }

    #[Test]
    public function classifiesOperationalByDefault(): void
    {
        $entry = $this->createEntry(['action' => 'user_login']);

        $result = $this->formatter->format($entry);

        self::assertSame('operational', $result->context['data_classification']);
    }

    #[Test]
    public function structuresBeforeAfterSnapshots(): void
    {
        $before = ['status' => 'draft', 'amount' => 100];
        $after = ['status' => 'approved', 'amount' => 100];

        $entry = $this->createEntry([
            'before' => $before,
            'after' => $after,
        ]);

        $result = $this->formatter->format($entry);

        self::assertArrayHasKey('change_snapshot', $result->context);
        $snapshot = $result->context['change_snapshot'];
        self::assertIsArray($snapshot);
        self::assertSame($before, $snapshot['before']);
        self::assertSame($after, $snapshot['after']);
        self::assertArrayNotHasKey('before', $result->context);
        self::assertArrayNotHasKey('after', $result->context);
    }

    #[Test]
    public function handlesOnlyBeforeSnapshot(): void
    {
        $entry = $this->createEntry(['before' => ['old' => 'data']]);

        $result = $this->formatter->format($entry);

        self::assertArrayHasKey('change_snapshot', $result->context);
        $snapshot = $result->context['change_snapshot'];
        self::assertIsArray($snapshot);
        self::assertSame(['old' => 'data'], $snapshot['before']);
        self::assertNull($snapshot['after']);
    }

    #[Test]
    public function handlesOnlyAfterSnapshot(): void
    {
        $entry = $this->createEntry(['after' => ['new' => 'data']]);

        $result = $this->formatter->format($entry);

        self::assertArrayHasKey('change_snapshot', $result->context);
        $snapshot = $result->context['change_snapshot'];
        self::assertIsArray($snapshot);
        self::assertNull($snapshot['before']);
        self::assertSame(['new' => 'data'], $snapshot['after']);
    }

    #[Test]
    public function preservesOtherContextFields(): void
    {
        $entry = $this->createEntry([
            'operator' => 'admin',
            'department' => 'finance',
        ]);

        $result = $this->formatter->format($entry);

        self::assertSame('admin', $result->context['operator']);
        self::assertSame('finance', $result->context['department']);
    }

    #[Test]
    public function returnsNewLogEntryInstance(): void
    {
        $entry = $this->createEntry([]);

        $result = $this->formatter->format($entry);

        self::assertNotSame($entry, $result);
        self::assertSame($entry->level, $result->level);
        self::assertSame($entry->message, $result->message);
        self::assertSame($entry->channel, $result->channel);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createEntry(array $context = []): LogEntry
    {
        return new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: $context,
            channel: 'finance',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
    }
}
