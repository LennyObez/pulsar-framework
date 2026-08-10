<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function assert;
use function is_string;
use function random_bytes;

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

    #[Test]
    public function buildMessageFromEntryProducesConsistentResult(): void
    {
        $timestamp = new DateTimeImmutable('2025-03-01T08:15:30.000000+00:00', new DateTimeZone('UTC'));

        $entry = AuditEntry::create(
            id: 'audit-msg-001',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'auditor@compliance.org',
            action: 'export_patient_records',
            resource: '/api/v1/patients/export',
            timestamp: $timestamp,
            metadata: ['format' => 'csv', 'record_count' => 1500],
            previousHmac: 'chain-prev-hmac-abc',
            auditKey: $this->auditKey,
        );

        $message1 = AuditEntry::buildMessageFromEntry($entry);
        $message2 = AuditEntry::buildMessageFromEntry($entry);

        self::assertSame($message1, $message2);
        self::assertStringContainsString('audit-msg-001', $message1);
        self::assertStringContainsString('auditor@compliance.org', $message1);
        self::assertStringContainsString('export_patient_records', $message1);
    }

    #[Test]
    public function buildMessageFromEntryIncludesKidField(): void
    {
        $entry = AuditEntry::create(
            id: 'kid-msg-test',
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'key_rotation',
            resource: 'crypto',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: [],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        $message = AuditEntry::buildMessageFromEntry($entry);

        self::assertStringContainsString($entry->kid, $message);
    }

    #[Test]
    public function toArrayContainsKidField(): void
    {
        $entry = AuditEntry::create(
            id: 'array-kid-test',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Failure,
            actor: 'unknown',
            action: 'login_attempt',
            resource: '/auth/login',
            timestamp: new DateTimeImmutable('2025-06-20T14:00:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: ['reason' => 'invalid_password'],
            previousHmac: 'prev-hmac',
            auditKey: $this->auditKey,
        );

        $array = $entry->toArray();

        self::assertArrayHasKey('kid', $array);
        $kid = $array['kid'];
        assert(is_string($kid));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $kid);
        self::assertSame($entry->kid, $array['kid']);
    }

    #[Test]
    public function verifyReturnsFalseWhenHmacTamperedViaReconstruction(): void
    {
        $timestamp = new DateTimeImmutable('2025-02-14T09:00:00.000000+00:00', new DateTimeZone('UTC'));

        $entry = AuditEntry::create(
            id: 'tamper-test',
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Denied,
            actor: 'malicious-user@evil.com',
            action: 'delete_all_records',
            resource: '/admin/nuke',
            timestamp: $timestamp,
            metadata: ['ip' => '10.0.0.1'],
            previousHmac: 'chain-seed',
            auditKey: $this->auditKey,
        );

        // Reconstruct with tampered HMAC
        $tampered = new AuditEntry(
            id: $entry->id,
            event: $entry->event,
            outcome: $entry->outcome,
            actor: $entry->actor,
            action: $entry->action,
            resource: $entry->resource,
            timestamp: $entry->timestamp,
            metadata: $entry->metadata,
            previousHmac: $entry->previousHmac,
            hmac: 'deadbeefdeadbeefdeadbeefdeadbeef',
            kid: $entry->kid,
        );

        self::assertFalse($tampered->verify($this->auditKey));
    }

    #[Test]
    public function constructorWithDefaultEmptyKid(): void
    {
        $entry = new AuditEntry(
            id: 'legacy-entry',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'legacy-system',
            action: 'read',
            resource: '/old-api',
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: [],
            previousHmac: 'prev',
            hmac: 'some-hmac-value',
        );

        self::assertSame('', $entry->kid);

        $array = $entry->toArray();
        self::assertSame('', $array['kid']);
    }

    #[Test]
    public function createProducesVerifiableChainOfThreeEntries(): void
    {
        $seed = 'genesis-hmac';

        $entry1 = AuditEntry::create(
            id: 'chain-1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'teller@bank.com',
            action: 'login',
            resource: '/auth',
            timestamp: new DateTimeImmutable('2025-03-07T08:00:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: ['branch' => 'downtown'],
            previousHmac: $seed,
            auditKey: $this->auditKey,
        );

        $entry2 = AuditEntry::create(
            id: 'chain-2',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'teller@bank.com',
            action: 'view_account',
            resource: '/accounts/12345678',
            timestamp: new DateTimeImmutable('2025-03-07T08:01:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: ['account_type' => 'savings'],
            previousHmac: $entry1->hmac,
            auditKey: $this->auditKey,
        );

        $entry3 = AuditEntry::create(
            id: 'chain-3',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'teller@bank.com',
            action: 'transfer',
            resource: '/transfers',
            timestamp: new DateTimeImmutable('2025-03-07T08:02:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: ['amount_cents' => 250000, 'currency' => 'USD'],
            previousHmac: $entry2->hmac,
            auditKey: $this->auditKey,
        );

        self::assertTrue($entry1->verify($this->auditKey));
        self::assertTrue($entry2->verify($this->auditKey));
        self::assertTrue($entry3->verify($this->auditKey));
        self::assertSame($entry1->hmac, $entry2->previousHmac);
        self::assertSame($entry2->hmac, $entry3->previousHmac);
    }

    /**
     * When metadata cannot be JSON-encoded (resources, closures,
     * recursive structures), `create()` must not propagate the
     * underlying `JsonException`. Throwing here breaks the audit
     * chain — the caller's mutex is released without the chain
     * advancing, the record is lost, and downstream logic continues
     * unaware. `create()` substitutes a sentinel metadata bag instead,
     * so the chain stays consistent and the incident is itself
     * auditable.
     *
     * Resource handles are the cleanest trigger because PHP's
     * `json_encode` rejects them unconditionally regardless of
     * version-specific Closure-encoding behaviour.
     */
    #[Test]
    public function createWithUnencodableMetadataDoesNotThrow(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertNotFalse($resource);

        $entry = AuditEntry::create(
            id: 'unencodable',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'service',
            action: 'write',
            resource: '/x',
            timestamp: new DateTimeImmutable('2025-03-07T08:00:00.000000+00:00', new DateTimeZone('UTC')),
            metadata: ['handle' => $resource],
            previousHmac: 'seed',
            auditKey: $this->auditKey,
        );

        fclose($resource);

        self::assertArrayHasKey(AuditEntry::SERIALIZATION_ERROR_KEY, $entry->metadata);
        self::assertNotEmpty($entry->hmac);
        // Verify the sentinel-substituted entry is still self-consistent.
        self::assertTrue($entry->verify($this->auditKey));
    }
}
