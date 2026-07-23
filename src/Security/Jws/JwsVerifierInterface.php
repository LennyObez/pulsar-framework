<?php

declare(strict_types=1);

namespace Pulsar\Security\Jws;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Verifies a compact JWS and returns its decoded payload.
 *
 * Implementations MUST fail closed: any structural, algorithm, certificate
 * chain, validity, or signature problem throws {@see JwsVerificationException}
 * rather than returning a payload. A returned payload means the signature was
 * cryptographically verified against a trusted anchor.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface JwsVerifierInterface
{
    /**
     * Verify $jws and return its decoded payload claims.
     *
     * @param DateTimeImmutable|null $now Reference time for certificate validity
     *                                    windows (defaults to the current time).
     *
     * @return array<string, mixed>
     *
     * @throws JwsVerificationException When verification fails for any reason.
     */
    public function verifyAndDecode(string $jws, ?DateTimeImmutable $now = null): array;
}
