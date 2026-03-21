<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Privacy;

use Pulsar\Api\Api;

/**
 * Contract for pseudonymizing signal data before storage.
 *
 * Implementations replace identifying information in claim values with
 * consistent pseudonyms, enabling analytics and audit while protecting
 * user privacy. The pseudonymization must be deterministic (same input
 * yields same pseudonym) but irreversible without the pseudonymization key.
 * @api
 */
#[Api(since: '1.0.0')]
interface PseudonymizerInterface
{
    /**
     * Pseudonymize a value, returning a consistent pseudonym.
     *
     * The same input with the same context must always produce the same pseudonym.
     * Different contexts may produce different pseudonyms for the same input
     * (domain separation).
     *
     * @param string $value The value to pseudonymize
     * @param string $context Domain separator (e.g., "ip_address", "device_id")
     * @return string The pseudonymized value
     */
    public function pseudonymize(string $value, string $context): string;
}
