<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * Port interface for persisting and retrieving notification preferences and consent records.
 * @api
 */
#[Api(since: '1.0.0')]
interface PreferenceStoreInterface
{
    /**
     * Get the current preferences for a notifiable entity.
     */
    public function getPreferences(string $notifiableId): UserPreferences;

    /**
     * Record a preference change with full consent audit trail.
     */
    public function updatePreference(
        string $notifiableId,
        string $channel,
        ConsentType $consentType,
        LegalBasis $legalBasis,
        ConsentSource $source,
        ?string $ipHash = null,
    ): void;

    /**
     * Get the full consent history for a notifiable entity.
     *
     * @return list<ConsentRecord>
     */
    public function getConsentHistory(string $notifiableId): array;
}
