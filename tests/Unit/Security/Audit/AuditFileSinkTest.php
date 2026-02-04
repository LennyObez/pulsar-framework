<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\ChainableAuditSinkInterface;

#[CoversClass(AuditFileSink::class)]
final class AuditFileSinkTest extends TestCase
{
    private string $tempDir;
    private string $auditKey;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_audit_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
        $this->auditKey = random_bytes(32);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $this->cleanDir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }

    private function createEntry(string $id = 'test-entry'): AuditEntry
    {
        return AuditEntry::create(
            id: $id,
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user@test.com',
            action: 'login',
            resource: '/auth',
            timestamp: new DateTimeImmutable('2025-01-15T10:00:00.000000+00:00'),
            metadata: ['ip' => '127.0.0.1'],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );
    }

    #[Test]
    public function writeCreatesFileAndAppendsJsonLine(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        $sink = new AuditFileSink($logPath);

        $entry = $this->createEntry();
        $sink->write($entry);

        self::assertFileExists($logPath);

        $contents = file_get_contents($logPath);
        self::assertIsString($contents);

        $lines = array_filter(explode("\n", $contents));
        self::assertCount(1, $lines);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('test-entry', $decoded['id']);
        self::assertSame('authentication', $decoded['event']);
    }

    #[Test]
    public function writeAppendsMultipleEntries(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        $sink = new AuditFileSink($logPath);

        $sink->write($this->createEntry('entry-1'));
        $sink->write($this->createEntry('entry-2'));
        $sink->write($this->createEntry('entry-3'));

        $contents = file_get_contents($logPath);
        self::assertIsString($contents);

        $lines = array_filter(explode("\n", $contents));
        self::assertCount(3, $lines);
    }

    #[Test]
    public function writeCreatesDirectoryIfMissing(): void
    {
        $logPath = $this->tempDir . '/nested/dir/audit.jsonl';
        $sink = new AuditFileSink($logPath);

        $sink->write($this->createEntry());

        self::assertFileExists($logPath);
        self::assertDirectoryExists($this->tempDir . '/nested/dir');
    }

    #[Test]
    public function implementsChainableAuditSinkInterface(): void
    {
        $sink = new AuditFileSink($this->tempDir . '/audit.jsonl');
        self::assertInstanceOf(ChainableAuditSinkInterface::class, $sink);
    }

    #[Test]
    public function lastHmacReadsCorrectHmacFromMultiLineFile(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        $sink = new AuditFileSink($logPath);

        $entry1 = $this->createEntry('entry-1');
        $entry2 = $this->createEntry('entry-2');
        $sink->write($entry1);
        $sink->write($entry2);

        self::assertSame($entry2->hmac, $sink->lastHmac());
    }

    #[Test]
    public function lastHmacReturnsNullForEmptyFile(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        file_put_contents($logPath, '');

        $sink = new AuditFileSink($logPath);

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function lastHmacReturnsNullForMissingFile(): void
    {
        $sink = new AuditFileSink($this->tempDir . '/nonexistent.jsonl');

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function lastHmacReturnsNullForCorruptLastLine(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        file_put_contents($logPath, "not valid json\n");

        $sink = new AuditFileSink($logPath);

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function writtenEntriesAreValidJson(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        $sink = new AuditFileSink($logPath);

        $entry = $this->createEntry();
        $sink->write($entry);

        $line = trim((string) file_get_contents($logPath));

        /** @var array<string, mixed> $data */
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('event', $data);
        self::assertArrayHasKey('outcome', $data);
        self::assertArrayHasKey('actor', $data);
        self::assertArrayHasKey('action', $data);
        self::assertArrayHasKey('resource', $data);
        self::assertArrayHasKey('timestamp', $data);
        self::assertArrayHasKey('metadata', $data);
        self::assertArrayHasKey('previous_hmac', $data);
        self::assertArrayHasKey('hmac', $data);
    }
}
