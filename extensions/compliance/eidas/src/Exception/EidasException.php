<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for eIDAS compliance failures.
 */
#[Api(since: '1.0.0')]
final class EidasException extends RuntimeException
{
    #[NoDiscard]
    public static function signatureVerificationFailed(string $reason): self
    {
        return new self(sprintf('Signature verification failed: %s', $reason));
    }

    #[NoDiscard]
    public static function unsupportedSignatureFormat(string $format): self
    {
        return new self(sprintf('Unsupported signature format: %s', $format));
    }

    #[NoDiscard]
    public static function sealVerificationFailed(string $reason): self
    {
        return new self(sprintf('Electronic seal verification failed: %s', $reason));
    }

    #[NoDiscard]
    public static function timestampRequestFailed(string $reason): self
    {
        return new self(sprintf('Timestamp request failed: %s', $reason));
    }

    #[NoDiscard]
    public static function timestampVerificationFailed(string $reason): self
    {
        return new self(sprintf('Timestamp verification failed: %s', $reason));
    }

    #[NoDiscard]
    public static function deliveryFailed(string $messageId, string $reason): self
    {
        return new self(sprintf('Registered delivery failed for message %s: %s', $messageId, $reason));
    }

    #[NoDiscard]
    public static function insufficientAssuranceLevel(string $required, string $actual): self
    {
        return new self(sprintf(
            'Insufficient assurance level: required %s, actual %s',
            $required,
            $actual,
        ));
    }

    /**
     * HMAC signatures are not suitable for production eIDAS compliance.
     *
     * Production deployments must use asymmetric cryptography (RSA/ECDSA)
     * with proper certificate chains via an OpenSSL-based implementation.
     */
    #[NoDiscard]
    public static function nonProductionSignature(): self
    {
        return new self(
            'HMAC-based signatures are not permitted outside testing environments. '
            . 'Production eIDAS compliance requires asymmetric cryptography (RSA/ECDSA) '
            . 'with qualified certificate chains. Configure an OpenSSL-based '
            . 'DigitalSignatureServiceInterface implementation for production use.',
        );
    }
}
