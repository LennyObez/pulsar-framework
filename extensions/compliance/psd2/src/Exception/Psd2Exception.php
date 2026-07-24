<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for PSD2 compliance failures.
 * @api
 */
#[Api(since: '1.0.0')]
final class Psd2Exception extends RuntimeException
{
    #[NoDiscard]
    public static function challengeNotFound(string $challengeId): self
    {
        return new self(sprintf('SCA challenge not found or expired: %s', $challengeId));
    }

    #[NoDiscard]
    public static function challengeExpired(string $challengeId): self
    {
        return new self(sprintf('SCA challenge has expired: %s', $challengeId));
    }

    #[NoDiscard]
    public static function dynamicLinkMismatch(string $challengeId): self
    {
        return new self(sprintf(
            'Transaction details do not match the SCA dynamic link for challenge: %s',
            $challengeId,
        ));
    }

    #[NoDiscard]
    public static function invalidAuthenticationCode(string $challengeId): self
    {
        return new self(sprintf('Invalid authentication code for challenge: %s', $challengeId));
    }

    #[NoDiscard]
    public static function certificateParseFailure(string $reason): self
    {
        return new self(sprintf('Failed to parse PSD2 certificate: %s', $reason));
    }

    #[NoDiscard]
    public static function certificateExpired(string $serialNumber): self
    {
        return new self(sprintf('PSD2 certificate has expired: %s', $serialNumber));
    }

    #[NoDiscard]
    public static function certificateNotQualified(string $serialNumber): self
    {
        return new self(sprintf('Certificate is not a qualified eIDAS certificate: %s', $serialNumber));
    }

    #[NoDiscard]
    public static function unauthorizedProvider(string $authorizationNumber): self
    {
        return new self(sprintf('Provider is not authorized: %s', $authorizationNumber));
    }

    #[NoDiscard]
    public static function scaRequired(string $transactionId): self
    {
        return new self(sprintf('SCA is required for transaction: %s', $transactionId));
    }

    #[NoDiscard]
    public static function scaSecretUnavailable(): self
    {
        return new self(
            'SCA dynamic linking requires a per-deployment secret key (>= 32 bytes) '
            . 'derived from the master key; configure PULSAR_MASTER_KEY.',
        );
    }

    #[NoDiscard]
    public static function trustAnchorsUnavailable(): self
    {
        return new self(
            'PSD2 certificate validation requires a configured eIDAS trust list '
            . '(psd2.certificate.trusted_ca_bundle_path); refusing to validate without one.',
        );
    }

    #[NoDiscard]
    public static function certificateChainUntrusted(string $serialNumber): self
    {
        return new self(sprintf(
            'PSD2 certificate does not chain to the configured eIDAS trust list: %s',
            $serialNumber,
        ));
    }

    public static function certificateRevoked(string $serialNumber): self
    {
        return new self(sprintf('PSD2 certificate has been revoked: %s', $serialNumber));
    }

    public static function revocationUnverified(string $serialNumber): self
    {
        return new self(sprintf(
            'PSD2 certificate revocation status could not be verified: %s',
            $serialNumber,
        ));
    }
}
