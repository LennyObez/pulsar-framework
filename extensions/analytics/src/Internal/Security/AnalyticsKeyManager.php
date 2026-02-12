<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Security;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\MasterKey;

/**
 * Analytics-specific key derivation manager.
 *
 * Derives a single stable visitor hashing key from the application MasterKey.
 * Daily uniqueness is achieved by including the UTC day number in the hash
 * input (see VisitorId::generate), not by rotating subkeys.
 *
 * SubkeyID: 20, Context: 'anal_vis' (8 bytes per libsodium KDF requirement).
 */
#[Internal(reason: 'Analytics security internals — use via service binding')]
final readonly class AnalyticsKeyManager
{
    private const int SUBKEY_ID = 20;
    private const string CONTEXT = 'anal_vis';

    public function __construct(
        private MasterKey $masterKey,
    ) {}

    /**
     * Derive the visitor hashing key.
     *
     * A single deterministic key is used for all days. Daily rotation is
     * handled by including the UTC day number in the HMAC input, ensuring
     * visitor hashes never repeat across days while keeping the key stable
     * for midnight grace-period lookups.
     */
    public function visitorKey(): string
    {
        return $this->masterKey->deriveSubKey(self::SUBKEY_ID, self::CONTEXT);
    }

    /**
     * Get the UTC day number for a given offset from today.
     *
     * Day offset 0 = today, 1 = yesterday.
     * Returns a monotonically increasing integer (days since Unix epoch)
     * that is included in the HMAC input for daily uniqueness.
     */
    public function utcDayNumber(int $dayOffset = 0): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($dayOffset > 0) {
            $now = $now->modify("-{$dayOffset} days");
        }

        return (int) ($now->getTimestamp() / 86400);
    }
}
