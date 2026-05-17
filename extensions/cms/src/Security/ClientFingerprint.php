<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Security;

use Pulsar\Api\Api;

/**
 * Client fingerprint value object for privacy-respecting client identification.
 *
 * Contains hashed representations of client attributes, never raw values,
 * to support rate limiting and abuse detection without storing PII.
 *
 * @psalm-api Public DTO returned from ClientFingerprintResolver; consumed
 *            by rate limiting and abuse detection middleware.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ClientFingerprint
{
    /**
     * @param string $ipAddress Resolved client IP address
     * @param string $ipHash BLAKE2b keyed hash of the IP address
     * @param string $userAgentHash BLAKE2b keyed hash of the User-Agent header
     * @param string $compositeHash BLAKE2b keyed hash of IP + User-Agent combined
     */
    public function __construct(
        public string $ipAddress,
        public string $ipHash,
        public string $userAgentHash,
        public string $compositeHash,
    ) {}
}
