<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate consent management.
 *
 * Used by GDPR, HL7/FHIR, and others.
 */
#[Api(since: '1.0.0')]
interface HasConsentManagement
{
    /**
     * Whether explicit consent must be obtained before data processing.
     */
    public function requiresExplicitConsent(): bool;

    /**
     * Whether consent withdrawal must be supported.
     */
    public function requiresConsentWithdrawal(): bool;
}
