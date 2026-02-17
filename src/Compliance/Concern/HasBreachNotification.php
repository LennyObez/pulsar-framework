<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate breach notification.
 *
 * Used by GDPR, NIS2, HIPAA, PSD2, and others.
 * This extends HasIncidentReporting with breach-specific requirements.
 */
#[Api(since: '1.0.0')]
interface HasBreachNotification extends HasIncidentReporting
{
    /**
     * Whether affected individuals must be notified (not just authorities).
     */
    public function requiresIndividualNotification(): bool;

    /**
     * Whether a breach register/log must be maintained.
     */
    public function requiresBreachRegister(): bool;
}
