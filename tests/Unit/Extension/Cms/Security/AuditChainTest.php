<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\Hmac;

use function random_bytes;
use function strlen;

/**
 * Security tests for the audit chain integrity.
 *
 * Verifies that audit entries are correctly HMAC'd and that the chain
 * structure allows tamper detection.
 */
#[CoversClass(AuditEntry::class)]
final class AuditChainTest extends TestCase
{
    private string $auditKey;

    protected function setUp(): void
    {
        // 32-byte key for BLAKE2b keyed hashing
        $this->auditKey = random_bytes(32);
    }

    // -- Basic entry creation and verification --------------------------------

    #[Test]
    public function auditEntryHasCorrectHmac(): void
    {
        $entry = AuditEntry::create(
            id: 'entry-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-123',
            action: 'cms.content.created',
            resource: 'content:abc-123',
            timestamp: new DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            metadata: ['title' => 'Test Post'],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        self::assertNotEmpty($entry->hmac);
        self::assertTrue($entry->verify($this->auditKey));
    }

    #[Test]
    public function auditEntryFailsVerificationWithWrongKey(): void
    {
        $entry = AuditEntry::create(
            id: 'entry-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-123',
            action: 'cms.content.created',
            resource: 'content:abc-123',
            timestamp: new DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        $wrongKey = random_bytes(32);

        self::assertFalse($entry->verify($wrongKey));
    }

    // -- Chain integrity verification ----------------------------------------

    #[Test]
    public function chainIntegrityWithMultipleEntries(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        // Entry 1 (genesis — no previous HMAC)
        $entry1 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'admin-1',
            action: 'cms.2fa.enrollment_started',
            resource: 'user:admin-1',
            timestamp: $now,
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        // Entry 2 — chains to entry 1
        $entry2 = AuditEntry::create(
            id: 'e2',
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'admin-1',
            action: 'cms.2fa.enrollment_confirmed',
            resource: 'user:admin-1',
            timestamp: new DateTimeImmutable('2026-01-15T10:01:00+00:00'),
            metadata: [],
            previousHmac: $entry1->hmac,
            auditKey: $this->auditKey,
        );

        // Entry 3 — chains to entry 2
        $entry3 = AuditEntry::create(
            id: 'e3',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'editor-1',
            action: 'cms.content.published',
            resource: 'content:post-42',
            timestamp: new DateTimeImmutable('2026-01-15T10:05:00+00:00'),
            metadata: ['title' => 'New Post'],
            previousHmac: $entry2->hmac,
            auditKey: $this->auditKey,
        );

        // All entries should verify independently
        self::assertTrue($entry1->verify($this->auditKey));
        self::assertTrue($entry2->verify($this->auditKey));
        self::assertTrue($entry3->verify($this->auditKey));

        // Chain links should be correct
        self::assertSame('', $entry1->previousHmac);
        self::assertSame($entry1->hmac, $entry2->previousHmac);
        self::assertSame($entry2->hmac, $entry3->previousHmac);
    }

    #[Test]
    public function tamperedEntryFailsVerification(): void
    {
        $entry = AuditEntry::create(
            id: 'tampered-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-123',
            action: 'cms.content.created',
            resource: 'content:abc-123',
            timestamp: new DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            metadata: ['title' => 'Original Title'],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        // Create a tampered entry by changing the action
        $tampered = new AuditEntry(
            id: $entry->id,
            event: $entry->event,
            outcome: $entry->outcome,
            actor: $entry->actor,
            action: 'cms.content.deleted', // TAMPERED
            resource: $entry->resource,
            timestamp: $entry->timestamp,
            metadata: $entry->metadata,
            previousHmac: $entry->previousHmac,
            hmac: $entry->hmac, // Original HMAC — now invalid
            kid: $entry->kid,
        );

        self::assertFalse($tampered->verify($this->auditKey));
    }

    #[Test]
    public function tamperedActorFailsVerification(): void
    {
        $entry = AuditEntry::create(
            id: 'actor-001',
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'admin-1',
            action: 'cms.plugin.installed',
            resource: 'plugin:malware-plugin',
            timestamp: new DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        // Forge a different actor
        $tampered = new AuditEntry(
            id: $entry->id,
            event: $entry->event,
            outcome: $entry->outcome,
            actor: 'attacker-1', // TAMPERED
            resource: $entry->resource,
            action: $entry->action,
            timestamp: $entry->timestamp,
            metadata: $entry->metadata,
            previousHmac: $entry->previousHmac,
            hmac: $entry->hmac,
            kid: $entry->kid,
        );

        self::assertFalse($tampered->verify($this->auditKey));
    }

    // -- Key ID (kid) is derived from the key --------------------------------

    #[Test]
    public function entryHasKeyId(): void
    {
        $entry = AuditEntry::create(
            id: 'kid-001',
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'cms.plugin.boot',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        self::assertNotEmpty($entry->kid);
        self::assertSame(16, strlen($entry->kid), 'kid should be 16 hex chars (64 bits)');
    }

    #[Test]
    public function sameKeyProducesSameKid(): void
    {
        $entry1 = AuditEntry::create(
            id: 'kid-a',
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'test-a',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'kid-b',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Failure,
            actor: 'user-1',
            action: 'test-b',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        self::assertSame($entry1->kid, $entry2->kid);
    }

    #[Test]
    public function differentKeyProducesDifferentKid(): void
    {
        $key2 = random_bytes(32);

        $entry1 = AuditEntry::create(
            id: 'kid-x',
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'test',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'kid-y',
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'test',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            auditKey: $key2,
        );

        self::assertNotSame($entry1->kid, $entry2->kid);
    }

    // -- toArray serialization -----------------------------------------------

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $entry = AuditEntry::create(
            id: 'arr-001',
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Denied,
            actor: 'user-999',
            action: 'cms.plugin.install_blocked',
            resource: 'plugin:suspicious',
            timestamp: new DateTimeImmutable('2026-01-15T12:00:00+00:00'),
            metadata: ['reason' => 'Unsigned package'],
            previousHmac: 'prev-hmac-hex',
            auditKey: $this->auditKey,
        );

        $arr = $entry->toArray();

        self::assertSame('arr-001', $arr['id']);
        self::assertSame('security_event', $arr['event']);
        self::assertSame('denied', $arr['outcome']);
        self::assertSame('user-999', $arr['actor']);
        self::assertSame('cms.plugin.install_blocked', $arr['action']);
        self::assertSame('plugin:suspicious', $arr['resource']);
        self::assertSame('prev-hmac-hex', $arr['previous_hmac']);
        self::assertNotEmpty($arr['hmac']);
        self::assertNotEmpty($arr['kid']);
        self::assertSame(['reason' => 'Unsigned package'], $arr['metadata']);
    }

    // -- Chain with metadata ------------------------------------------------

    #[Test]
    public function metadataChangesProduceDifferentHmacs(): void
    {
        $ts = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $entry1 = AuditEntry::create(
            id: 'meta-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-1',
            action: 'cms.content.updated',
            resource: 'content:42',
            timestamp: $ts,
            metadata: ['field' => 'title', 'old' => 'Old Title', 'new' => 'New Title'],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'meta-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-1',
            action: 'cms.content.updated',
            resource: 'content:42',
            timestamp: $ts,
            metadata: ['field' => 'title', 'old' => 'Old Title', 'new' => 'Tampered Title'],
            previousHmac: '',
            auditKey: $this->auditKey,
        );

        self::assertNotSame($entry1->hmac, $entry2->hmac);
    }
}
