<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use JsonException;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;
use SodiumException;

/**
 * Verifies audit entry HMACs and chain integrity using a key ring.
 *
 * Supports entries with kid (looks up the specific key) and legacy entries
 * without kid (tries all keys in the ring). Chain verification checks that
 * each entry's previousHmac matches the preceding entry's hmac.
 */
#[Api(since: '1.0.0')]
final readonly class AuditChainVerifier
{
    public function __construct(
        private KeyRingInterface $keyRing,
    ) {}

    /**
     * Verify a single entry's HMAC using the key ring.
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function verifyEntry(AuditEntry $entry): bool
    {
        $message = AuditEntry::buildMessageFromEntry($entry);

        if ($entry->kid !== '') {
            $key = $this->keyRing->keyFor($entry->kid);
            if ($key === null) {
                return false;
            }

            return Hmac::verifyHex($message, $entry->hmac, $key);
        }

        // Legacy entry without kid — try all keys in the ring.
        foreach ($this->keyRing->all() as $key) {
            if (Hmac::verifyHex($message, $entry->hmac, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify an entire chain of entries (HMAC integrity + chain linkage).
     *
     * Entries must be in order (oldest first). Each entry's previousHmac
     * must match the preceding entry's hmac.
     *
     * @param list<AuditEntry> $entries Ordered entries (oldest first)
     * @param string|null $expectedFirstPreviousHmac Expected previousHmac of the first entry (seed HMAC)
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function verifyChain(array $entries, ?string $expectedFirstPreviousHmac = null): AuditChainResult
    {
        $verifiedCount = 0;
        $failedEntryIds = [];
        $brokenLinks = [];

        $previousHmac = $expectedFirstPreviousHmac;

        foreach ($entries as $entry) {
            // Check chain linkage
            if ($previousHmac !== null && $entry->previousHmac !== $previousHmac) {
                $brokenLinks[] = $entry->id;
            }

            // Verify HMAC
            if ($this->verifyEntry($entry)) {
                $verifiedCount++;
            } else {
                $failedEntryIds[] = $entry->id;
            }

            $previousHmac = $entry->hmac;
        }

        $valid = $failedEntryIds === [] && $brokenLinks === [];

        return new AuditChainResult(
            valid: $valid,
            verifiedCount: $verifiedCount,
            failedEntryIds: $failedEntryIds,
            brokenLinks: $brokenLinks,
        );
    }
}
