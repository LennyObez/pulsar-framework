<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\AuditLogPurge;
use Pulsar\DataProtection\DefaultRetentionPolicy;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(AuditLogPurge::class)]
final class AuditLogPurgeTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audit_purge_');

        if ($path === false) {
            self::fail('Could not create temp file');
        }

        $this->logPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    #[Test]
    public function purgeRemovesExpiredEntries(): void
    {
        $this->writeEntries([
            ['timestamp' => '2020-01-01T00:00:00+00:00', 'event' => 'old'],
            ['timestamp' => '2020-06-01T00:00:00+00:00', 'event' => 'also_old'],
            ['timestamp' => new DateTimeImmutable()->format(DateTimeImmutable::ATOM), 'event' => 'recent'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365, 'GDPR Art. 17');

        $count = $purge->purge($policy);

        self::assertSame(2, $count);
    }

    #[Test]
    public function purgeRetainsNonExpiredEntries(): void
    {
        $recentTimestamp = new DateTimeImmutable()->format(DateTimeImmutable::ATOM);

        $this->writeEntries([
            ['timestamp' => $recentTimestamp, 'event' => 'keep_this'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        self::assertSame(0, $count);
    }

    #[Test]
    public function purgeReturnsZeroForMissingFile(): void
    {
        unlink($this->logPath);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        self::assertSame(0, $purge->purge($policy));
    }

    #[Test]
    public function countExpiredWithoutDeleting(): void
    {
        $this->writeEntries([
            ['timestamp' => '2020-01-01T00:00:00+00:00', 'event' => 'expired_1'],
            ['timestamp' => '2020-06-01T00:00:00+00:00', 'event' => 'expired_2'],
            ['timestamp' => new DateTimeImmutable()->format(DateTimeImmutable::ATOM), 'event' => 'recent'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->countExpired($policy);

        self::assertSame(2, $count);

        // Verify file still has all 3 entries
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logPath)), fn($l) => trim($l) !== '');
        self::assertCount(3, $lines);
    }

    #[Test]
    public function purgeWithIndefiniteRetentionDoesNothing(): void
    {
        $this->writeEntries([
            ['timestamp' => '2000-01-01T00:00:00+00:00', 'event' => 'very_old'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 0); // Indefinite

        $count = $purge->purge($policy);

        self::assertSame(0, $count);
    }

    #[Test]
    public function purgeHandlesEntriesWithoutTimestamp(): void
    {
        $this->writeEntries([
            ['event' => 'no_timestamp'], // Missing timestamp — should be retained
            ['timestamp' => '2020-01-01T00:00:00+00:00', 'event' => 'expired'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        // Only the entry with a timestamp that's expired is purged
        self::assertSame(1, $count);
    }

    #[Test]
    public function purgeHandlesEmptyFile(): void
    {
        file_put_contents($this->logPath, '');

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        self::assertSame(0, $purge->purge($policy));
    }

    #[Test]
    public function purgeParsesMicrosecondTimestampFormat(): void
    {
        $this->writeEntries([
            ['timestamp' => '2020-01-01T00:00:00.123456+00:00', 'event' => 'old_microsecond'],
            ['timestamp' => new DateTimeImmutable()->format(DateTimeImmutable::ATOM), 'event' => 'recent'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        self::assertSame(1, $count);
    }

    #[Test]
    public function purgeSkipsEntriesWithNonStringTimestamp(): void
    {
        $this->writeEntries([
            ['timestamp' => 12345, 'event' => 'numeric_timestamp'],
            ['timestamp' => '2020-01-01T00:00:00+00:00', 'event' => 'expired'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        // Only the valid expired entry is purged; numeric timestamp is retained
        self::assertSame(1, $count);
    }

    #[Test]
    public function purgeSkipsEntriesWithUnparseableTimestamp(): void
    {
        $this->writeEntries([
            ['timestamp' => 'not-a-date', 'event' => 'bad_format'],
            ['timestamp' => '2020-01-01T00:00:00+00:00', 'event' => 'expired'],
        ]);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        // Only the parseable expired entry is purged; bad format is retained
        self::assertSame(1, $count);
    }

    #[Test]
    public function purgeHandlesInvalidJsonLines(): void
    {
        // Write mix of valid JSON and garbage
        file_put_contents($this->logPath, implode("\n", [
            '{"timestamp":"2020-01-01T00:00:00+00:00","event":"expired"}',
            'not json at all',
            '{"timestamp":"' . new DateTimeImmutable()->format(DateTimeImmutable::ATOM) . '","event":"recent"}',
            '',
        ]));

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        // Only the valid expired entry is purged; invalid JSON is skipped
        self::assertSame(1, $count);
    }

    #[Test]
    public function countExpiredReturnsZeroForMissingFile(): void
    {
        unlink($this->logPath);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        self::assertSame(0, $purge->countExpired($policy));
    }

    #[Test]
    public function purgeDoesNotRewriteFileWhenNothingPurged(): void
    {
        $recentTimestamp = new DateTimeImmutable()->format(DateTimeImmutable::ATOM);
        $this->writeEntries([
            ['timestamp' => $recentTimestamp, 'event' => 'recent'],
        ]);

        $modTimeBefore = filemtime($this->logPath);

        // Ensure file mtime can change
        usleep(10000);

        $purge = new AuditLogPurge($this->logPath);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $count = $purge->purge($policy);

        self::assertSame(0, $count);

        // File should not have been rewritten
        clearstatcache(true, $this->logPath);
        self::assertSame($modTimeBefore, filemtime($this->logPath));
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeEntries(array $entries): void
    {
        $content = '';

        foreach ($entries as $entry) {
            $content .= json_encode($entry, JSON_THROW_ON_ERROR) . "\n";
        }

        file_put_contents($this->logPath, $content);
    }
}
