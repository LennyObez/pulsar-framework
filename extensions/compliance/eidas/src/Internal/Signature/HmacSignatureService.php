<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Internal\Signature;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Eidas\Contracts\DigitalSignatureServiceInterface;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;
use Pulsar\Extension\Eidas\Domain\SignatureInfo;
use Pulsar\Extension\Eidas\Exception\EidasException;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function getenv;
use function hash_hmac;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * HMAC-based signature service for development and testing.
 *
 * Production implementations should use asymmetric cryptography (RSA/ECDSA)
 * with proper certificate chains. This implementation uses HMAC-SHA256
 * as a symmetric equivalent for local testing.
 */
#[Internal(reason: 'Use DigitalSignatureServiceInterface with a production-grade implementation')]
final readonly class HmacSignatureService implements DigitalSignatureServiceInterface
{
    /** @var array<string, string> Key ID => HMAC key */
    private array $keys;

    private string $appEnv;

    /**
     * @param array<string, string> $keys Map of key IDs to HMAC secrets
     * @param string $appEnv Application environment (defaults to APP_ENV env var)
     */
    public function __construct(
        array $keys = [],
        private ?AuditLoggerInterface $auditLogger = null,
        string $appEnv = '',
    ) {
        $this->keys = $keys;

        if ($appEnv === '') {
            $env = getenv('APP_ENV');
            $this->appEnv = $env !== false ? $env : 'production';
        } else {
            $this->appEnv = $appEnv;
        }
    }

    #[Override]
    public function sign(string $data, string $signerKeyId, SignatureFormat $format = SignatureFormat::JAdES): string
    {
        $this->guardNonProduction();

        $key = $this->keys[$signerKeyId] ?? null;

        if ($key === null) {
            throw EidasException::signatureVerificationFailed('Unknown signer key: ' . $signerKeyId);
        }

        $mac = hash_hmac('sha256', $data, $key);
        $now = new DateTimeImmutable();

        $envelope = json_encode([
            'mac' => $mac,
            'signer' => $signerKeyId,
            'format' => $format->value,
            'signed_at' => $now->format('Y-m-d\TH:i:s.uP'),
        ], JSON_THROW_ON_ERROR);

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $signerKeyId,
            'eidas_document_signed',
            metadata: ['format' => $format->value],
        );

        return $envelope;
    }

    #[Override]
    public function verify(string $data, string $signature, SignatureFormat $format = SignatureFormat::JAdES): SignatureInfo
    {
        $this->guardNonProduction();
        /** @var array{mac?: string, signer?: string, format?: string, signed_at?: string} $envelope */
        $envelope = json_decode($signature, true, 512, JSON_THROW_ON_ERROR);

        $signerKeyId = $envelope['signer'] ?? '';
        $expectedMac = $envelope['mac'] ?? '';
        $signedAt = isset($envelope['signed_at']) ? new DateTimeImmutable($envelope['signed_at']) : new DateTimeImmutable();

        $key = $this->keys[$signerKeyId] ?? null;

        if ($key === null) {
            return new SignatureInfo(
                valid: false,
                signerName: $signerKeyId,
                signerIdentifier: $signerKeyId,
                format: $format,
                isQualified: false,
                signedAt: $signedAt,
                reason: 'Unknown signer key',
            );
        }

        $actualMac = hash_hmac('sha256', $data, $key);
        $valid = hash_equals($expectedMac, $actualMac);

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            $valid ? AuditOutcome::Success : AuditOutcome::Failure,
            $signerKeyId,
            'eidas_signature_verified',
            metadata: ['format' => $format->value, 'valid' => $valid ? 'true' : 'false'],
        );

        return new SignatureInfo(
            valid: $valid,
            signerName: $signerKeyId,
            signerIdentifier: $signerKeyId,
            format: $format,
            isQualified: false,
            signedAt: $signedAt,
            reason: $valid ? '' : 'MAC mismatch',
        );
    }

    /**
     * Prevent HMAC signatures from being used outside testing environments.
     *
     * @throws EidasException
     */
    private function guardNonProduction(): void
    {
        if ($this->appEnv !== 'testing' && $this->appEnv !== 'test') {
            throw EidasException::nonProductionSignature();
        }
    }
}
