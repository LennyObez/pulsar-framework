<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Eidas\Domain\TimestampToken;
use Pulsar\Extension\Eidas\Exception\EidasException;

/**
 * Timestamp service per eIDAS Art. 41-42 and RFC 3161.
 * @api
 */
#[Api(since: '1.0.0')]
interface TimestampServiceInterface
{
    /**
     * Request a timestamp for the given data.
     *
     * @param string $data The data to timestamp
     * @param string $hashAlgorithm Hash algorithm (default: SHA-256)
     *
     * @throws EidasException On timestamp request failure
     */
    public function timestamp(string $data, string $hashAlgorithm = 'sha256'): TimestampToken;

    /**
     * Verify a timestamp token against the original data.
     *
     * @param string $data The original data
     * @param TimestampToken $token The token to verify
     *
     * @return bool True if the timestamp is valid
     *
     * @throws EidasException On verification failure
     */
    public function verifyTimestamp(string $data, TimestampToken $token): bool;
}
