<?php

declare(strict_types=1);

namespace Pulsar\Security\Jws;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Thrown when a compact JWS fails verification.
 *
 * Every failure mode — malformed structure, unexpected algorithm, an invalid or
 * unpinned certificate chain, an expired certificate, or a bad signature — is a
 * verification failure. Callers must treat it as "reject", never as a warning.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class JwsVerificationException extends RuntimeException
{
    public static function fromReason(string $reason): self
    {
        return new self($reason);
    }
}
