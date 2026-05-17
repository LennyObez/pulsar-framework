<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable DTO representing a qualified timestamp token per RFC 3161.
 *
 * Contains the timestamp authority's response including the hash of the
 * timestamped data, the timestamp itself, and the TSA's identity.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TimestampToken
{
    /**
     * @param bool                  $valid       Whether the timestamp token is valid
     * @param string                $dataHash    Hash of the timestamped data
     * @param string                $hashAlgorithm Algorithm used for hashing (e.g., "sha256")
     * @param DateTimeImmutable     $timestamp   The qualified timestamp
     * @param string                $tsaSubject  Distinguished name of the Time Stamp Authority
     * @param string                $serialNumber TSA-assigned serial number for this token
     * @param string                $reason      Failure reason if not valid, empty otherwise
     */
    public function __construct(
        public bool $valid,
        public string $dataHash,
        public string $hashAlgorithm,
        public DateTimeImmutable $timestamp,
        public string $tsaSubject,
        public string $serialNumber = '',
        public string $reason = '',
    ) {}
}
