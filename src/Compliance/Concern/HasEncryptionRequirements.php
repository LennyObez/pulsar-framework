<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate encryption.
 *
 * Used by PCI-DSS, HIPAA, GDPR, ISO 27001, NIS2, and others.
 * @api
 */
#[Api(since: '1.0.0')]
interface HasEncryptionRequirements
{
    /**
     * Whether encryption at rest is required.
     */
    public function requiresEncryptionAtRest(): bool;

    /**
     * Whether encryption in transit (TLS) is required.
     */
    public function requiresEncryptionInTransit(): bool;
}
