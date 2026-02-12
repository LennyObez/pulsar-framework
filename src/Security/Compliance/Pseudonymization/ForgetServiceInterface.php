<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use Pulsar\Api\Api;
use Pulsar\Security\Compliance\Exception\ComplianceException;

/**
 * Contract for right-to-be-forgotten erasure of pseudonym mappings.
 *
 * Implementations delete the pseudonym mapping for a given subject
 * and produce an auditable record of the deletion. This contract
 * supports controls for GDPR Article 17 right-to-erasure requirements.
 */
#[Api(since: '1.0.0')]
interface ForgetServiceInterface
{
    /**
     * Erase the pseudonym mapping for the given subject identifier.
     *
     * @throws ComplianceException If no mapping exists for the subject
     */
    public function forget(string $subjectId): ForgetResult;
}
