<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Mobile;

use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Security\Jws\AppleJwsVerifierFactory;
use Pulsar\Security\Jws\JwsVerificationException;

/**
 * Verifies Apple JWS (JSON Web Signature) payloads.
 *
 * Delegates to the framework's shared {@see AppleJwsVerifierFactory} verifier,
 * which performs the full ES256 x5c chain validation pinned to the bundled
 * Apple Root CA G3. Two properties of that verifier are load-bearing: x5c[0]
 * is never trusted on its own — a self-signed certificate would otherwise
 * pass — and the raw JOSE signature is converted to DER before verification,
 * without which genuine Apple notifications are rejected.
 */
#[Internal]
final class JwsVerifier
{
    /**
     * Verify a JWS signature and return the decoded payload.
     *
     * @return array<string, mixed> The decoded payload
     *
     * @throws PaymentException If the JWS structure is invalid or signature verification fails
     */
    public static function verifyAndDecode(string $jws): array
    {
        if ($jws === '') {
            throw PaymentException::jwsVerificationFailed('empty JWS token');
        }

        try {
            return AppleJwsVerifierFactory::create()->verifyAndDecode($jws);
        } catch (JwsVerificationException $e) {
            throw PaymentException::jwsVerificationFailed($e->getMessage());
        }
    }
}
