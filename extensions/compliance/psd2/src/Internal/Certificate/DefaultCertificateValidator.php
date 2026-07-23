<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Psd2\Config\CertificateConfig;
use Pulsar\Extension\Psd2\Contracts\CertificateValidatorInterface;
use Pulsar\Extension\Psd2\Domain\CertificateInfo;
use Pulsar\Extension\Psd2\Domain\CertificateType;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_key_exists;
use function array_values;
use function is_array;
use function is_string;
use function openssl_x509_checkpurpose;
use function openssl_x509_parse;
use function str_contains;

use const X509_PURPOSE_ANY;

/**
 * Default certificate validator using OpenSSL.
 *
 * Parses PEM certificates, extracts PSD2-specific fields,
 * and validates qualification status and expiry.
 */
#[Internal(reason: 'Use CertificateValidatorInterface')]
final readonly class DefaultCertificateValidator implements CertificateValidatorInterface
{
    /** @var array<string, bool> */
    private array $authorizedProviders;

    /**
     * @param list<string> $authorizedProviders Known authorization numbers
     */
    public function __construct(
        private CertificateConfig $config,
        array $authorizedProviders = [],
        private ?AuditLoggerInterface $auditLogger = null,
    ) {
        $mapped = [];

        foreach ($authorizedProviders as $number) {
            $mapped[$number] = true;
        }

        $this->authorizedProviders = $mapped;
    }

    #[Override]
    public function validate(string $pemCertificate): CertificateInfo
    {
        $parsed = openssl_x509_parse($pemCertificate);

        if (!is_array($parsed)) {
            throw Psd2Exception::certificateParseFailure('OpenSSL could not parse the certificate');
        }

        /** @var array{
         *     subject?: array{CN?: string},
         *     issuer?: array{CN?: string},
         *     serialNumberHex?: string,
         *     validFrom_time_t?: int,
         *     validTo_time_t?: int,
         *     extensions?: array<string, mixed>,
         * } $parsed
         */
        $subjectArr = $parsed['subject'] ?? [];
        $issuerArr = $parsed['issuer'] ?? [];
        $subject = $subjectArr['CN'] ?? '';
        $issuer = $issuerArr['CN'] ?? '';
        $serialNumber = $parsed['serialNumberHex'] ?? '';

        $validFromTs = $parsed['validFrom_time_t'] ?? 0;
        $validToTs = $parsed['validTo_time_t'] ?? 0;
        $validFrom = new DateTimeImmutable('@' . $validFromTs);
        $validUntil = new DateTimeImmutable('@' . $validToTs);

        $now = new DateTimeImmutable();

        if ($now > $validUntil) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_certificate_expired',
                metadata: ['serial_number' => $serialNumber],
            );

            throw Psd2Exception::certificateExpired($serialNumber);
        }

        // Trust must come from the eIDAS chain, not from self-declared string
        // fields: verify the certificate chains to the configured trust list
        // BEFORE any of its fields (type, roles, NCA, authorization number) are
        // read, and fail closed when no trust list is configured.
        $this->assertTrustedChain($pemCertificate, $serialNumber);

        // Extract PSD2-specific fields from extensions
        /** @var mixed $rawExtensions */
        $rawExtensions = $parsed['extensions'] ?? [];
        $extensions = is_array($rawExtensions) ? $rawExtensions : [];

        $psd2Roles = $this->extractPsd2Roles($extensions);
        $authorizationNumber = $this->extractAuthorizationNumber($extensions);
        $ncaName = $this->extractNcaName($extensions);
        $ncaId = $this->extractNcaId($extensions);

        // Determine certificate type from subject/extensions
        $type = str_contains($subject, 'QWAC') || str_contains($subject, 'Web Authentication')
            ? CertificateType::Qwac
            : CertificateType::Qseal;

        $isQualified = $this->checkQualification($extensions);

        if ($this->config->requireQualified && !$isQualified) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_certificate_not_qualified',
                metadata: ['serial_number' => $serialNumber],
            );

            throw Psd2Exception::certificateNotQualified($serialNumber);
        }

        $info = new CertificateInfo(
            type: $type,
            subject: $subject,
            issuer: $issuer,
            serialNumber: $serialNumber,
            authorizationNumber: $authorizationNumber,
            psd2Roles: $psd2Roles,
            ncaName: $ncaName,
            ncaId: $ncaId,
            validFrom: $validFrom,
            validUntil: $validUntil,
            isQualified: $isQualified,
        );

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            null,
            'psd2_certificate_validated',
            metadata: [
                'serial_number' => $serialNumber,
                'type' => $type->value,
                'authorization_number' => $authorizationNumber,
            ],
        );

        return $info;
    }

    #[Override]
    public function isAuthorized(string $authorizationNumber): bool
    {
        return array_key_exists($authorizationNumber, $this->authorizedProviders);
    }

    /**
     * Fail closed unless the certificate cryptographically chains to the
     * configured eIDAS trust list. Without this, the validator would derive
     * trust from self-declared, attacker-suppliable string fields, so a
     * self-signed certificate carrying the right strings would be "authorized".
     *
     * NOTE: this establishes chain trust and validity. Full eIDAS conformance —
     * OCSP/CRL revocation checking and ASN.1 parsing of the ETSI TS 119 495
     * QcStatements for precise PSD2 roles/NCA data (rather than the string hints
     * below) — remains to be layered on; until then a deployment MUST treat the
     * derived roles as advisory and pair them with its own allowlist.
     */
    private function assertTrustedChain(string $pemCertificate, string $serialNumber): void
    {
        $bundle = $this->config->trustedCaBundlePath;

        if ($bundle === null || $bundle === '') {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_certificate_trust_list_missing',
                metadata: ['serial_number' => $serialNumber],
            );

            throw Psd2Exception::trustAnchorsUnavailable();
        }

        // openssl_x509_checkpurpose builds and verifies the chain against the CA
        // bundle (and enforces validity); true only when the certificate anchors
        // to a trusted CA in the bundle.
        if (openssl_x509_checkpurpose($pemCertificate, X509_PURPOSE_ANY, [$bundle]) !== true) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'psd2_certificate_untrusted_chain',
                metadata: ['serial_number' => $serialNumber],
            );

            throw Psd2Exception::certificateChainUntrusted($serialNumber);
        }
    }

    /**
     * Extract PSD2 roles from certificate extensions.
     *
     * @param array<array-key, mixed> $extensions
     *
     * @return list<string>
     */
    private function extractPsd2Roles(array $extensions): array
    {
        // PSD2 roles are stored in QcStatements extension
        // In practice, this requires ASN.1 parsing of the extension
        // For the framework, we extract from subject/extensions hints
        $roles = [];

        /** @var mixed $value */
        foreach ($extensions as $value) {
            if (!is_string($value)) {
                continue;
            }

            // Look for PSD2 role indicators
            if (str_contains($value, 'PSP_AI')) {
                $roles[] = 'PSP_AI';
            }

            if (str_contains($value, 'PSP_PI')) {
                $roles[] = 'PSP_PI';
            }

            if (str_contains($value, 'PSP_AS')) {
                $roles[] = 'PSP_AS';
            }

            if (str_contains($value, 'PSP_IC')) {
                $roles[] = 'PSP_IC';
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * Extract the NCA authorization number from certificate extensions.
     *
     * @param array<array-key, mixed> $extensions
     */
    private function extractAuthorizationNumber(array $extensions): string
    {
        /** @var mixed $value */
        foreach ($extensions as $value) {
            if (!is_string($value)) {
                continue;
            }

            // Authorization numbers typically follow pattern: PSDXX-YYYY-ZZZZ
            if (preg_match('/PSD[A-Z]{2}-[A-Z]+-\S+/', $value, $matches) === 1) {
                return $matches[0];
            }
        }

        return '';
    }

    /**
     * @param array<array-key, mixed> $extensions
     */
    private function extractNcaName(array $extensions): string
    {
        /** @var mixed $value */
        foreach ($extensions as $value) {
            if (is_string($value) && str_contains($value, 'NCA')) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<array-key, mixed> $extensions
     */
    private function extractNcaId(array $extensions): string
    {
        /** @var mixed $value */
        foreach ($extensions as $oid => $value) {
            if (is_string($value) && str_contains((string) $oid, 'NCAId')) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Check whether the certificate is a qualified eIDAS certificate.
     *
     * @param array<array-key, mixed> $extensions
     */
    private function checkQualification(array $extensions): bool
    {
        /** @var mixed $value */
        foreach ($extensions as $oid => $value) {
            if (!is_string($value)) {
                continue;
            }

            $oidStr = (string) $oid;

            // QcStatements OID: 1.3.6.1.5.5.7.1.3
            if (str_contains($oidStr, '1.3.6.1.5.5.7.1.3')) {
                return true;
            }

            // Look for qualified certificate indicators
            if (str_contains($value, 'QcCompliance') || str_contains($value, 'qcStatements')) {
                return true;
            }
        }

        return false;
    }
}
