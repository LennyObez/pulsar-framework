<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use Pulsar\Api\Api;

/**
 * Service for creating and verifying qualified timestamps per eIDAS Articles 41-42.
 *
 * Implements the RFC 3161 Time-Stamp Protocol for obtaining qualified
 * electronic time stamps from a trusted Time Stamp Authority (TSA).
 */
#[Api(since: '1.0.0')]
interface TimestampServiceInterface
{
    /**
     * Request a qualified timestamp for the given data.
     *
     * Creates a hash of the data and submits a timestamp request to the
     * configured TSA. The returned token binds the data hash to a trusted
     * timestamp.
     *
     * @param string $data          The data to timestamp
     * @param string $hashAlgorithm Hash algorithm to use (default: "sha256")
     *
     * @return TimestampToken The timestamp token from the TSA
     */
    public function timestamp(string $data, string $hashAlgorithm = 'sha256'): TimestampToken;

    /**
     * Verify a timestamp token against the original data.
     *
     * Checks that the token's hash matches the data and that the TSA's
     * signature on the token is valid.
     *
     * @param string         $data  The original timestamped data
     * @param TimestampToken $token The timestamp token to verify
     *
     * @return TimestampToken Updated token with verification result
     */
    public function verifyTimestamp(string $data, TimestampToken $token): TimestampToken;
}
