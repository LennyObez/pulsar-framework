<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\Hmac;

use function explode;
use function file_get_contents;
use function json_decode;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(AuditLogger::class)]
#[CoversClass(AuditEntry::class)]
#[CoversClass(AuditFileSink::class)]
final class AuditChainTamperEvidenceTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/pulsar_audit_chain_test_' . bin2hex(random_bytes(8)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    #[Test]
    public function intact_chain_validates_successfully(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::Authentication, AuditOutcome::Success, 'admin', 'login', 'session:1');
        $logger->log(AuditEvent::DataAccess, AuditOutcome::Success, 'admin', 'view', 'users:list');
        $logger->log(AuditEvent::DataModification, AuditOutcome::Success, 'admin', 'update', 'user:42');

        $entries = $this->readEntries();

        self::assertCount(3, $entries);
        $this->assertChainIsValid($entries, $auditKey);
    }

    #[Test]
    public function modifying_action_field_breaks_chain(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::Authentication, AuditOutcome::Success, 'admin', 'login', 'session:1');
        $logger->log(AuditEvent::Authorization, AuditOutcome::Denied, 'attacker', 'delete', 'database:prod');
        $logger->log(AuditEvent::DataAccess, AuditOutcome::Success, 'admin', 'view', 'users:list');

        $entries = $this->readEntries();
        self::assertCount(3, $entries);

        // Tamper with the second entry: change 'delete' to 'view' to hide the action
        $entries[1]['action'] = 'view';

        $this->assertTamperedEntryDetected($entries, $auditKey, 1);
    }

    #[Test]
    public function modifying_actor_field_breaks_chain(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::DataModification, AuditOutcome::Success, 'malicious-insider', 'delete', 'records:all');
        $logger->log(AuditEvent::SystemEvent, AuditOutcome::Success, 'system', 'backup', 'data:full');

        $entries = $this->readEntries();
        self::assertCount(2, $entries);

        // Tamper: change actor to hide who performed the deletion
        $entries[0]['actor'] = 'system';

        $this->assertTamperedEntryDetected($entries, $auditKey, 0);
    }

    #[Test]
    public function modifying_outcome_field_breaks_chain(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::Authorization, AuditOutcome::Denied, 'user', 'access', 'admin-panel');

        $entries = $this->readEntries();
        self::assertCount(1, $entries);

        // Tamper: change denied to success to hide an access violation
        $entries[0]['outcome'] = AuditOutcome::Success->value;

        $this->assertTamperedEntryDetected($entries, $auditKey, 0);
    }

    #[Test]
    public function deleting_an_entry_breaks_the_chain_for_subsequent_entries(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::Authentication, AuditOutcome::Success, 'admin', 'login', 'session:1');
        $logger->log(AuditEvent::DataModification, AuditOutcome::Success, 'admin', 'delete', 'evidence:critical');
        $logger->log(AuditEvent::SystemEvent, AuditOutcome::Success, 'system', 'maintenance', 'cache:clear');

        $entries = $this->readEntries();
        self::assertCount(3, $entries);

        // Delete the middle entry (the one with critical evidence)
        $entriesWithDeletion = [$entries[0], $entries[2]];

        // Individual entry HMACs remain valid since the entry data hasn't changed --
        // but chain validation detects the gap. The third entry's previous_hmac
        // references the deleted entry's hmac, which no longer precedes it in the log.
        $firstEntry = $this->hydrateEntry($entriesWithDeletion[0]);
        $thirdEntry = $this->hydrateEntry($entriesWithDeletion[1]);

        // The chain link is broken: entry[2]'s previous_hmac points to the
        // deleted entry[1]'s hmac, not to entry[0]'s hmac
        self::assertNotSame(
            $firstEntry->hmac,
            $thirdEntry->previousHmac,
            'Chain should be broken when middle entry is deleted',
        );

        // Full chain validation should fail on the reduced set
        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $auditKey);
        $chainValid = true;
        $previousHmac = $seedHmac;

        foreach ($entriesWithDeletion as $rawEntry) {
            $entry = $this->hydrateEntry($rawEntry);

            if ($entry->previousHmac !== $previousHmac) {
                $chainValid = false;

                break;
            }

            $previousHmac = $entry->hmac;
        }

        self::assertFalse($chainValid, 'Chain validation must fail when an entry is deleted');
    }

    #[Test]
    public function modifying_metadata_breaks_chain(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(
            AuditEvent::ConfigurationChange,
            AuditOutcome::Success,
            'admin',
            'config.update',
            'security.mfa',
            ['old_value' => true, 'new_value' => false],
        );

        $entries = $this->readEntries();
        self::assertCount(1, $entries);

        // Tamper: change metadata to hide that MFA was disabled
        $entries[0]['metadata'] = ['old_value' => false, 'new_value' => false];

        $this->assertTamperedEntryDetected($entries, $auditKey, 0);
    }

    #[Test]
    public function modifying_timestamp_breaks_chain(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::DataAccess, AuditOutcome::Success, 'user', 'export', 'customer-data');

        $entries = $this->readEntries();
        self::assertCount(1, $entries);

        // Tamper: backdate the timestamp to hide when the export actually occurred
        $entries[0]['timestamp'] = '2020-01-01T00:00:00.000000+00:00';

        $this->assertTamperedEntryDetected($entries, $auditKey, 0);
    }

    #[Test]
    public function chain_seed_is_deterministic_from_key(): void
    {
        $auditKey = random_bytes(32);

        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $auditKey);

        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $entry = $logger->log(AuditEvent::SystemEvent, AuditOutcome::Success, 'system', 'boot', 'kernel');

        // The first entry's previous_hmac should equal the deterministic seed
        self::assertSame($seedHmac, $entry->previousHmac);
    }

    #[Test]
    public function wrong_key_fails_verification(): void
    {
        $realKey = random_bytes(32);
        $wrongKey = random_bytes(32);

        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $realKey);

        $entry = $logger->log(AuditEvent::Authentication, AuditOutcome::Success, 'admin', 'login', 'session:1');

        // Verification with the wrong key should fail
        self::assertFalse($entry->verify($wrongKey));

        // Verification with the correct key should pass
        self::assertTrue($entry->verify($realKey));
    }

    #[Test]
    public function chain_resumes_from_last_entry_on_restart(): void
    {
        $auditKey = random_bytes(32);

        // First logger writes entries
        $sink1 = new AuditFileSink($this->logPath);
        $logger1 = new AuditLogger($sink1, $auditKey);

        $entry1 = $logger1->log(AuditEvent::SystemEvent, AuditOutcome::Success, 'system', 'boot', 'kernel');
        $entry2 = $logger1->log(AuditEvent::Authentication, AuditOutcome::Success, 'admin', 'login', 'session:1');

        // Second logger (simulating process restart) should resume the chain
        $sink2 = new AuditFileSink($this->logPath);
        $logger2 = new AuditLogger($sink2, $auditKey);

        $entry3 = $logger2->log(AuditEvent::DataAccess, AuditOutcome::Success, 'admin', 'view', 'dashboard');

        // Entry 3's previousHmac should match entry 2's hmac (chain continuation)
        self::assertSame($entry2->hmac, $entry3->previousHmac);

        // Full chain should validate
        $entries = $this->readEntries();
        self::assertCount(3, $entries);
        $this->assertChainIsValid($entries, $auditKey);
    }

    #[Test]
    public function swapping_entry_order_breaks_chain(): void
    {
        $auditKey = random_bytes(32);
        $sink = new AuditFileSink($this->logPath);
        $logger = new AuditLogger($sink, $auditKey);

        $logger->log(AuditEvent::Authentication, AuditOutcome::Success, 'admin', 'login', 'session:1');
        $logger->log(AuditEvent::DataModification, AuditOutcome::Success, 'admin', 'delete', 'record:99');
        $logger->log(AuditEvent::SystemEvent, AuditOutcome::Success, 'system', 'gc', 'sessions');

        $entries = $this->readEntries();
        self::assertCount(3, $entries);

        // Swap entries 1 and 2 to reorder the log
        $swapped = [$entries[0], $entries[2], $entries[1]];

        // At least one entry in the swapped chain should fail verification
        $chainValid = true;
        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $auditKey);
        $previousHmac = $seedHmac;

        foreach ($swapped as $rawEntry) {
            $entry = $this->hydrateEntry($rawEntry);

            if ($entry->previousHmac !== $previousHmac) {
                $chainValid = false;

                break;
            }

            if (!$entry->verify($auditKey)) {
                $chainValid = false;

                break;
            }

            $previousHmac = $entry->hmac;
        }

        self::assertFalse($chainValid, 'Swapping entry order should break the chain');
    }

    /**
     * Read all JSONL entries from the audit log file.
     *
     * @return list<array<string, mixed>>
     */
    private function readEntries(): array
    {
        $content = file_get_contents($this->logPath);
        self::assertNotFalse($content, 'Audit log file should be readable');

        $lines = explode("\n", trim($content));
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Hydrate a raw array entry into an AuditEntry object for verification.
     *
     * @param array<string, mixed> $raw
     */
    private function hydrateEntry(array $raw): AuditEntry
    {
        self::assertIsString($raw['id']);
        self::assertIsString($raw['event']);
        self::assertIsString($raw['outcome']);
        self::assertIsString($raw['actor']);
        self::assertIsString($raw['action']);
        self::assertIsString($raw['resource']);
        self::assertIsString($raw['timestamp']);
        self::assertIsString($raw['previous_hmac']);
        self::assertIsString($raw['hmac']);
        self::assertIsArray($raw['metadata']);

        $kid = $raw['kid'] ?? '';
        self::assertIsString($kid);

        /** @var array<string, mixed> $metadata */
        $metadata = $raw['metadata'];

        return new AuditEntry(
            id: $raw['id'],
            event: AuditEvent::from($raw['event']),
            outcome: AuditOutcome::from($raw['outcome']),
            actor: $raw['actor'],
            action: $raw['action'],
            resource: $raw['resource'],
            timestamp: new DateTimeImmutable($raw['timestamp']),
            metadata: $metadata,
            previousHmac: $raw['previous_hmac'],
            hmac: $raw['hmac'],
            kid: $kid,
        );
    }

    /**
     * Assert that the full chain of entries is valid.
     *
     * @param list<array<string, mixed>> $rawEntries
     */
    private function assertChainIsValid(array $rawEntries, string $auditKey): void
    {
        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $auditKey);
        $previousHmac = $seedHmac;

        foreach ($rawEntries as $i => $rawEntry) {
            $entry = $this->hydrateEntry($rawEntry);

            self::assertSame(
                $previousHmac,
                $entry->previousHmac,
                "Entry $i should chain from previous HMAC",
            );

            self::assertTrue(
                $entry->verify($auditKey),
                "Entry $i HMAC should verify against the audit key",
            );

            $previousHmac = $entry->hmac;
        }
    }

    /**
     * Assert that a specific entry fails HMAC verification after tampering.
     *
     * @param list<array<string, mixed>> $tamperedEntries
     */
    private function assertTamperedEntryDetected(array $tamperedEntries, string $auditKey, int $tamperedIndex): void
    {
        $entry = $this->hydrateEntry($tamperedEntries[$tamperedIndex]);

        self::assertFalse(
            $entry->verify($auditKey),
            "Tampered entry at index $tamperedIndex should fail HMAC verification",
        );
    }
}
