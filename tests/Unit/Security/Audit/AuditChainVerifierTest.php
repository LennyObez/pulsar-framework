<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditChainResult;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;

#[CoversClass(AuditChainVerifier::class)]
#[CoversClass(AuditChainResult::class)]
final class AuditChainVerifierTest extends TestCase
{
    private string $auditKey;
    private EnvKeyRing $keyRing;
    private AuditChainVerifier $verifier;

    protected function setUp(): void
    {
        $hex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex);
        $this->auditKey = $masterKey->deriveSubKey(2, 'audit___');
        $this->keyRing = EnvKeyRing::fromMasterKey($masterKey, 2, 'audit___');
        $this->verifier = new AuditChainVerifier($this->keyRing);
    }

    #[Test]
    public function verifyEntryReturnsTrueForValidEntry(): void
    {
        $entry = $this->createEntry('e1', 'seed');

        self::assertTrue($this->verifier->verifyEntry($entry));
    }

    #[Test]
    public function verifyEntryReturnsFalseForTamperedEntry(): void
    {
        $entry = $this->createEntry('e1', 'seed');

        // Tamper by creating a new entry with different action but same hmac
        $tampered = new AuditEntry(
            id: $entry->id,
            event: $entry->event,
            outcome: $entry->outcome,
            actor: $entry->actor,
            action: 'tampered_action',
            resource: $entry->resource,
            timestamp: $entry->timestamp,
            metadata: $entry->metadata,
            previousHmac: $entry->previousHmac,
            hmac: $entry->hmac,
            kid: $entry->kid,
        );

        self::assertFalse($this->verifier->verifyEntry($tampered));
    }

    #[Test]
    public function verifyEntryReturnsFalseForUnknownKid(): void
    {
        $entry = new AuditEntry(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: 'seed',
            hmac: 'fake_hmac',
            kid: 'unknown_kid_value',
        );

        self::assertFalse($this->verifier->verifyEntry($entry));
    }

    #[Test]
    public function verifyChainPassesForValidChain(): void
    {
        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey);

        $entry1 = $this->createEntry('e1', $seedHmac);
        $entry2 = $this->createEntry('e2', $entry1->hmac);
        $entry3 = $this->createEntry('e3', $entry2->hmac);

        $result = $this->verifier->verifyChain([$entry1, $entry2, $entry3], $seedHmac);

        self::assertTrue($result->valid);
        self::assertSame(3, $result->verifiedCount);
        self::assertSame([], $result->failedEntryIds);
        self::assertSame([], $result->brokenLinks);
    }

    #[Test]
    public function verifyChainDetectsBrokenLink(): void
    {
        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey);

        $entry1 = $this->createEntry('e1', $seedHmac);
        // entry2 has wrong previousHmac (doesn't chain to entry1)
        $entry2 = $this->createEntry('e2', 'wrong_previous_hmac');

        $result = $this->verifier->verifyChain([$entry1, $entry2], $seedHmac);

        self::assertFalse($result->valid);
        self::assertSame(2, $result->verifiedCount);
        self::assertSame([], $result->failedEntryIds);
        self::assertContains('e2', $result->brokenLinks);
    }

    #[Test]
    public function verifyChainDetectsTamperedEntry(): void
    {
        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey);

        $entry1 = $this->createEntry('e1', $seedHmac);

        // Create a tampered entry
        $tampered = new AuditEntry(
            id: 'e2',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'tampered',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $entry1->hmac,
            hmac: 'fake_hmac_value',
            kid: $entry1->kid,
        );

        $result = $this->verifier->verifyChain([$entry1, $tampered], $seedHmac);

        self::assertFalse($result->valid);
        self::assertSame(1, $result->verifiedCount);
        self::assertContains('e2', $result->failedEntryIds);
    }

    #[Test]
    public function verifyChainAcrossKeyRotation(): void
    {
        // Create entries with old key
        $oldHex = sodium_bin2hex(random_bytes(32));
        $oldMasterKey = MasterKey::fromHex($oldHex);
        $oldAuditKey = $oldMasterKey->deriveSubKey(2, 'audit___');

        $seedHmac = Hmac::computeHex('PULSAR_AUDIT_SEED', $oldAuditKey);
        $entry1 = AuditEntry::create(
            id: 'e1',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $seedHmac,
            auditKey: $oldAuditKey,
        );

        // Rotate keys: new master key with old as previous
        $newHex = sodium_bin2hex(random_bytes(32));
        $rotatedMasterKey = MasterKey::fromHex($newHex, $oldHex);
        $newAuditKey = $rotatedMasterKey->deriveSubKey(2, 'audit___');

        // Create entry2 with new key, chained to entry1
        $entry2 = AuditEntry::create(
            id: 'e2',
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'read',
            resource: '/data',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $entry1->hmac,
            auditKey: $newAuditKey,
        );

        // Verify with key ring that has both keys
        $keyRing = EnvKeyRing::fromMasterKey($rotatedMasterKey, 2, 'audit___');
        $verifier = new AuditChainVerifier($keyRing);

        // Both entries should verify — entry1 with old key, entry2 with new key
        self::assertTrue($verifier->verifyEntry($entry1));
        self::assertTrue($verifier->verifyEntry($entry2));

        // Different kids
        self::assertNotSame($entry1->kid, $entry2->kid);

        // Full chain passes
        $result = $verifier->verifyChain([$entry1, $entry2], $seedHmac);
        self::assertTrue($result->valid);
        self::assertSame(2, $result->verifiedCount);
    }

    #[Test]
    public function verifyChainWithEmptyEntriesIsValid(): void
    {
        $result = $this->verifier->verifyChain([]);

        self::assertTrue($result->valid);
        self::assertSame(0, $result->verifiedCount);
    }

    private function createEntry(string $id, string $previousHmac): AuditEntry
    {
        return AuditEntry::create(
            id: $id,
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: $previousHmac,
            auditKey: $this->auditKey,
        );
    }
}
