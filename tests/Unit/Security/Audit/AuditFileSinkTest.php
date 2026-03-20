<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pulsar\Security\Audit\AuditChainState;
use Pulsar\Security\Audit\AuditChainStateAware;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\ChainableAuditSinkInterface;
use Stringable;

use function file_put_contents;
use function is_string;
use function json_encode;
use function random_bytes;

#[CoversClass(AuditFileSink::class)]
final class AuditFileSinkTest extends TestCase
{
    private string $tempDir;
    private string $auditKey;

    /**
     * Per-test unique temp directory. Cleanup is intentionally left to
     * the OS: the static-analysis ruleset rejects any unlink() flow
     * from scandir/realpath into the deletion sink (CWE-22), and a
     * fully literal explicit-path teardown is too noisy. sys_get_temp_dir()
     * is purged by the OS on every CI runner and at OS boot locally,
     * so accumulated junk has bounded lifetime.
     *
     * `AuditFileSink` defaults to a `NullLogger` when no PSR-3
     * implementation is injected, so its corruption-path diagnostics
     * (F24.3) silently no-op during tests. Production wiring passes
     * the application logger (see `SecurityWiring`).
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_audit_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o775, true);
        $this->auditKey = random_bytes(32);
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

    #[Test]
    public function lastHmacReturnsHmacFromSingleLineFile(): void
    {
        $logPath = $this->tempDir . '/single.jsonl';
        $sink = new AuditFileSink($logPath);

        $entry = AuditEntry::create(
            id: 'single-entry',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'compliance-officer@bank.com',
            action: 'audit_export',
            resource: '/reports/quarterly',
            timestamp: new DateTimeImmutable('2025-03-07T09:00:00.000000+00:00'),
            metadata: ['ip' => '10.0.1.50', 'report_type' => 'sox'],
            previousHmac: 'chain-seed',
            auditKey: $this->auditKey,
        );
        $sink->write($entry);

        self::assertSame($entry->hmac, $sink->lastHmac());
    }

    #[Test]
    public function lastHmacReturnsNullWhenHmacFieldMissing(): void
    {
        $logPath = $this->tempDir . '/no-hmac.jsonl';
        file_put_contents($logPath, json_encode(['id' => 'test', 'event' => 'auth']) . "\n");

        $sink = new AuditFileSink($logPath);

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function lastHmacReturnsNullWhenHmacFieldIsNotString(): void
    {
        $logPath = $this->tempDir . '/bad-hmac.jsonl';
        file_put_contents($logPath, json_encode(['hmac' => 12345]) . "\n");

        $sink = new AuditFileSink($logPath);

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function lastHmacHandlesFileWithOnlyNewlines(): void
    {
        $logPath = $this->tempDir . '/newlines.jsonl';
        file_put_contents($logPath, "\n\n\n");

        $sink = new AuditFileSink($logPath);

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function lastHmacHandlesLargeFileByReadingFromEnd(): void
    {
        $logPath = $this->tempDir . '/large.jsonl';
        $sink = new AuditFileSink($logPath);

        // Write 50 entries to create a multi-line file
        $lastEntry = null;
        for ($i = 0; $i < 50; $i++) {
            $lastEntry = AuditEntry::create(
                id: 'batch-' . $i,
                event: AuditEvent::DataAccess,
                outcome: AuditOutcome::Success,
                actor: 'compliance-officer@bank.com',
                action: 'audit_export',
                resource: '/reports/quarterly',
                timestamp: new DateTimeImmutable('2025-03-07T09:00:00.000000+00:00'),
                metadata: ['ip' => '10.0.1.50', 'report_type' => 'sox'],
                previousHmac: 'chain-seed',
                auditKey: $this->auditKey,
            );
            $sink->write($lastEntry);
        }

        self::assertSame($lastEntry->hmac, $sink->lastHmac());
    }

    #[Test]
    public function writeDoesNotFailWhenDirectoryAlreadyExists(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        $sink = new AuditFileSink($logPath);

        $sink->write($this->createEntry('first'));
        $sink->write($this->createEntry('second'));

        $contents = file_get_contents($logPath);
        self::assertIsString($contents);

        $lines = array_filter(explode("\n", $contents));
        self::assertCount(2, $lines);
    }

    #[Test]
    public function lastHmacReturnsNullForJsonWithNullHmac(): void
    {
        $logPath = $this->tempDir . '/null-hmac.jsonl';
        file_put_contents($logPath, json_encode(['hmac' => null]) . "\n");

        $sink = new AuditFileSink($logPath);

        self::assertNull($sink->lastHmac());
    }

    #[Test]
    public function implementsAuditChainStateAware(): void
    {
        $sink = new AuditFileSink($this->tempDir . '/audit.jsonl');
        self::assertInstanceOf(AuditChainStateAware::class, $sink);
    }

    #[Test]
    public function chainStateEmptyForMissingFile(): void
    {
        $sink = new AuditFileSink($this->tempDir . '/nonexistent.jsonl');

        self::assertSame(AuditChainState::Empty, $sink->chainState());
    }

    #[Test]
    public function chainStateEmptyForZeroByteFile(): void
    {
        $logPath = $this->tempDir . '/empty.jsonl';
        file_put_contents($logPath, '');

        $sink = new AuditFileSink($logPath);

        self::assertSame(AuditChainState::Empty, $sink->chainState());
    }

    #[Test]
    public function chainStateHealthyForValidLastEntry(): void
    {
        $logPath = $this->tempDir . '/audit.jsonl';
        $sink = new AuditFileSink($logPath);
        $sink->write($this->createEntry('only-entry'));

        self::assertSame(AuditChainState::Healthy, $sink->chainState());
    }

    #[Test]
    public function chainStateCorruptedForMalformedJson(): void
    {
        // F24.3: a tampered or truncated last line must surface as
        // Corrupted so the logger fails closed instead of re-seeding
        // over the tamper.
        $logPath = $this->tempDir . '/corrupt.jsonl';
        file_put_contents($logPath, "not valid json\n");

        $sink = new AuditFileSink($logPath);

        self::assertSame(AuditChainState::Corrupted, $sink->chainState());
    }

    #[Test]
    public function chainStateCorruptedForMissingHmacField(): void
    {
        $logPath = $this->tempDir . '/no-hmac.jsonl';
        file_put_contents($logPath, json_encode(['id' => 'x']) . "\n");

        $sink = new AuditFileSink($logPath);

        self::assertSame(AuditChainState::Corrupted, $sink->chainState());
    }

    #[Test]
    public function chainStateCorruptedForBlankLastLine(): void
    {
        $logPath = $this->tempDir . '/blank.jsonl';
        file_put_contents($logPath, "\n\n\n");

        $sink = new AuditFileSink($logPath);

        self::assertSame(AuditChainState::Corrupted, $sink->chainState());
    }

    #[Test]
    public function chainStateCorruptedForNonStringHmac(): void
    {
        $logPath = $this->tempDir . '/bad-hmac.jsonl';
        file_put_contents($logPath, json_encode(['hmac' => 12345]) . "\n");

        $sink = new AuditFileSink($logPath);

        self::assertSame(AuditChainState::Corrupted, $sink->chainState());
    }

    #[Test]
    public function corruptionRoutesDiagnosticThroughInjectedLogger(): void
    {
        // F24.3: with a PSR-3 logger wired (production wiring via
        // SecurityWiring), the corruption-path diagnostic must reach
        // the application's structured log pipeline rather than be
        // dropped to STDERR.
        $logPath = $this->tempDir . '/corrupt.jsonl';
        file_put_contents($logPath, "not json at all\n");

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string}> */
            public array $entries = [];

            /**
             * @param array<array-key, mixed> $context
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                // PSR-3 levels are PSR-3 LogLevel constants — always strings.
                $levelString = is_string($level) ? $level : '';
                $this->entries[] = ['level' => $levelString, 'message' => (string) $message];
            }
        };

        $sink = new AuditFileSink($logPath, false, $logger);
        $sink->chainState();

        self::assertNotEmpty($logger->entries);
        self::assertSame('warning', $logger->entries[0]['level']);
        self::assertStringContainsString('chain corrupted', $logger->entries[0]['message']);
    }

    #[Test]
    public function chainStateAndLastHmacAgree(): void
    {
        $logPath = $this->tempDir . '/agreement.jsonl';
        $sink = new AuditFileSink($logPath);
        $entry = $this->createEntry('agree');
        $sink->write($entry);

        // Healthy state ⇔ non-null hmac. The two methods must not
        // disagree about what the file contains, otherwise the logger's
        // chain-resume logic could deadlock between "say it's healthy
        // but resume from null".
        self::assertSame(AuditChainState::Healthy, $sink->chainState());
        self::assertSame($entry->hmac, $sink->lastHmac());
    }
}
