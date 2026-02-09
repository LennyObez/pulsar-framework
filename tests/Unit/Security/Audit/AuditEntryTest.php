<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AuditEntry::class)]
final class AuditEntryTest extends TestCase
{
    private string $auditKey;

    protected function setUp(): void
    {
        $this->auditKey = random_bytes(32);
    }

    #[Test]
    public function createProducesValidEntry(): void
    {
        $timestamp = new DateTimeImmutable('2025-01-15T10:30:00.000000+00:00');

        $entry = AuditEntry::create(
            id: 'entry-001',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user@example.com',
            action: 'login',
            resource: '/auth/login',
            timestamp: $timestamp,
            metadata: ['ip' => '192.168.1.1'],
            previousHmac: 'seed_hmac_value',
            auditKey: $this->auditKey,
        );

        self::assertSame('entry-001', $entry->id);
        self::assertSame(AuditEvent::Authentication, $entry->event);
        self::assertSame(AuditOutcome::Success, $entry->outcome);
        self::assertSame('user@example.com', $entry->actor);
        self::assertSame('login', $entry->action);
        self::assertSame('/auth/login', $entry->resource);
        self::assertSame($timestamp, $entry->timestamp);
        self::assertSame(['ip' => '192.168.1.1'], $entry->metadata);
        self::assertSame('seed_hmac_value', $entry->previousHmac);
        self::assertNotEmpty($entry->hmac);
    }

    #[Test]
    public function verifyReturnsTrueForUntamperedEntry(): void
    {
        $entry = AuditEntry::create(
            id: 'entry-002',
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Denied,
            actor: 'admin',
            action: 'access_restricted',
            resource: '/admin/settings',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'prev',
            auditKey: $this->auditKey,
        );

        self::assertTrue($entry->verify($this->auditKey));
    }

    #[Test]
    public function verifyReturnsFalseForWrongKey(): void
    {
        $entry = AuditEntry::create(
            id: 'entry-003',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'service',
            action: 'read',
            resource: '/api/users',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'prev',
            auditKey: $this->auditKey,
        );

        $wrongKey = random_bytes(32);

        self::assertFalse($entry->verify($wrongKey));
    }

    #[Test]
    public function createComputesKidFromAuditKey(): void
    {
        $entry = AuditEntry::create(
            id: 'kid-test',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        // kid should be 16 hex chars
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $entry->kid);
    }

    #[Test]
    public function kidIsDeterministicForSameKey(): void
    {
        $entry1 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'e2',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'read',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $entry1->hmac,
            auditKey: $this->auditKey,
        );

        self::assertSame($entry1->kid, $entry2->kid);
    }

    #[Test]
    public function differentKeysProduceDifferentKids(): void
    {
        $otherKey = random_bytes(32);

        $entry1 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'seed',
            auditKey: $otherKey,
        );

        self::assertNotSame($entry1->kid, $entry2->kid);
    }

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $timestamp = new DateTimeImmutable('2025-06-01T12:00:00.000000+00:00');

        $entry = AuditEntry::create(
            id: 'entry-004',
            event: AuditEvent::ConfigurationChange,
            outcome: AuditOutcome::Success,
            actor: 'admin',
            action: 'update_setting',
            resource: 'app.debug',
            timestamp: $timestamp,
            metadata: ['old' => 'false', 'new' => 'true'],
            previousHmac: 'chain_previous',
            auditKey: $this->auditKey,
        );

        $array = $entry->toArray();

        self::assertSame('entry-004', $array['id']);
        self::assertSame('configuration_change', $array['event']);
        self::assertSame('success', $array['outcome']);
        self::assertSame('admin', $array['actor']);
        self::assertSame('update_setting', $array['action']);
        self::assertSame('app.debug', $array['resource']);
        self::assertSame('2025-06-01T12:00:00.000000+00:00', $array['timestamp']);
        self::assertSame(['old' => 'false', 'new' => 'true'], $array['metadata']);
        self::assertSame('chain_previous', $array['previous_hmac']);
        self::assertNotEmpty($array['hmac']);
        self::assertNotEmpty($array['kid']);
    }

    #[Test]
    public function hmacChainEnforcesOrder(): void
    {
        $entry1 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user1',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'e2',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'user1',
            action: 'read',
            resource: '/data',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $entry1->hmac,
            auditKey: $this->auditKey,
        );

        // Both entries verify individually
        self::assertTrue($entry1->verify($this->auditKey));
        self::assertTrue($entry2->verify($this->auditKey));

        // entry2's previousHmac must match entry1's hmac
        self::assertSame($entry1->hmac, $entry2->previousHmac);
    }

    #[Test]
    public function differentMetadataProducesDifferentHmac(): void
    {
        $base = [
            'id' => 'same-id',
            'event' => AuditEvent::Authentication,
            'outcome' => AuditOutcome::Success,
            'actor' => 'user',
            'action' => 'login',
            'resource' => '',
            'timestamp' => new DateTimeImmutable('2025-01-01T00:00:00.000000+00:00'),
            'previousHmac' => 'seed',
            'auditKey' => $this->auditKey,
        ];

        $entry1 = AuditEntry::create(...[...$base, 'metadata' => ['key' => 'value1']]);
        $entry2 = AuditEntry::create(...[...$base, 'metadata' => ['key' => 'value2']]);

        self::assertNotSame($entry1->hmac, $entry2->hmac);
    }

    #[Test]
    public function fieldsContainingDelimitersProduceDistinctHmacs(): void
    {
        $ts = new DateTimeImmutable('2025-01-01T00:00:00.000000+00:00');

        // Actor "admin\nlogin" vs actor "admin" + action "login" should never collide
        $entry1 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: "admin\nlogin",
            action: 'x',
            resource: '',
            timestamp: $ts,
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'admin',
            action: "login\nx",
            resource: '',
            timestamp: $ts,
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        self::assertNotSame($entry1->hmac, $entry2->hmac);
    }

    #[Test]
    public function multiBytUtf8MetadataProducesCorrectHmac(): void
    {
        $ts = new DateTimeImmutable('2025-01-01T00:00:00.000000+00:00');

        $entry = AuditEntry::create(
            id: 'utf8-test',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'operador',
            action: 'transferência',
            resource: '/pagamento',
            timestamp: $ts,
            metadata: ['descrição' => 'Ação: €100'],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        self::assertTrue($entry->verify($this->auditKey));

        // Different UTF-8 content must produce different HMAC
        $entry2 = AuditEntry::create(
            id: 'utf8-test',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'operador',
            action: 'transferência',
            resource: '/pagamento',
            timestamp: $ts,
            metadata: ['descrição' => 'Ação: £100'],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        self::assertNotSame($entry->hmac, $entry2->hmac);
    }
}
