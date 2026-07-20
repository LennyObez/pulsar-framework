<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Security;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\KeyProviderInterface;

/**
 * Analytics-specific key derivation manager.
 *
 * Provides the STABLE consent-persistence key and the UTC day numbers.
 *
 * Visitor TRACKING no longer uses this stable key: it is keyed by disposable
 * per-day salts (see VisitorSaltStoreInterface / DbVisitorSaltStore) so that a
 * purged day's hashes become irreversible (forward secrecy). The stable key
 * derived here backs only the consent hash, which must persist across days —
 * a visitor's recorded consent choice has to remain re-identifiable to be
 * honored, so it deliberately does not rotate.
 *
 * SubkeyID: 20, Context: 'anal_vis' (8 bytes per libsodium KDF requirement).
 */
#[Internal(reason: 'Analytics security internals; use via service binding')]
final readonly class AnalyticsKeyManager
{
    private const int SUBKEY_ID = 20;
    private const string CONTEXT = 'anal_vis';

    public function __construct(
        private KeyProviderInterface $masterKey,
    ) {}

    /**
     * Derive the stable consent-persistence key.
     *
     * A single deterministic key, stable across days on purpose: it backs the
     * consent hash, and a visitor's consent choice must stay re-identifiable to
     * be honored. Visitor tracking does NOT use this — it uses disposable
     * per-day salts for forward secrecy (see VisitorSaltStoreInterface).
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
            $now = $now->modify("-$dayOffset days");
        }

        return (int) ($now->getTimestamp() / 86400);
    }
}
