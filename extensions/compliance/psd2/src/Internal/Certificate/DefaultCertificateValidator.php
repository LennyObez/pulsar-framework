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
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\IssuerResolver;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationCheckerInterface;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_key_exists;
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

    private Psd2QcStatementsParser $qcStatementsParser;

    private IssuerResolver $issuerResolver;

    /**
     * @param list<string> $authorizedProviders Known authorization numbers
     */
    public function __construct(
        private CertificateConfig $config,
        array $authorizedProviders = [],
        private ?AuditLoggerInterface $auditLogger = null,
        ?Psd2QcStatementsParser $qcStatementsParser = null,
        private ?RevocationCheckerInterface $revocationChecker = null,
        ?IssuerResolver $issuerResolver = null,
    ) {
        $mapped = [];

        foreach ($authorizedProviders as $number) {
            $mapped[$number] = true;
        }

        $this->authorizedProviders = $mapped;
        $this->qcStatementsParser = $qcStatementsParser ?? new Psd2QcStatementsParser();
        $this->issuerResolver = $issuerResolver ?? new IssuerResolver();
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

        // A certificate can chain to a trusted CA yet have been revoked since
        // issuance; confirm it is not, via OCSP (with the configured policy for
        // an inconclusive answer), before trusting its contents.
        $this->assertNotRevoked($pemCertificate, $serialNumber);

        // Extract PSD2-specific fields from extensions
        /** @var mixed $rawExtensions */
        $rawExtensions = $parsed['extensions'] ?? [];
        $extensions = is_array($rawExtensions) ? $rawExtensions : [];

        // PSD2 attributes come from the ASN.1 qcStatements extension (ETSI TS
        // 119 495), decoded structurally rather than string-matched against the
        // certificate text.
        $psd2 = $this->qcStatementsParser->parseCertificate($pemCertificate);
        $psd2Roles = $psd2?->roles ?? [];
        $ncaName = $psd2?->ncaName ?? '';
        $ncaId = $psd2?->ncaId ?? '';
        $isQualified = $psd2?->qualified ?? false;

        $authorizationNumber = $this->extractAuthorizationNumber($extensions);

        // Determine certificate type from subject/extensions
        $type = str_contains($subject, 'QWAC') || str_contains($subject, 'Web Authentication')
            ? CertificateType::Qwac
            : CertificateType::Qseal;

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
     * NOTE: this establishes chain trust and validity. PSD2 roles/NCA data are
     * parsed from the ASN.1 qcStatements extension (see
     * {@see Psd2QcStatementsParser}); revocation is confirmed separately by
     * {@see assertNotRevoked()}.
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
     * Confirm the certificate has not been revoked.
     *
     * Revocation needs the issuing CA certificate (to build the OCSP CertID and
     * verify the responder signature), resolved from the trust bundle. When no
     * revocation checker is wired, revocation is disabled by config, or the
     * issuer cannot be resolved (a trust anchor, or an issuing CA absent from
     * the bundle), the check is skipped and audited — it never fabricates a
     * "good" verdict. A confirmed revocation always rejects; an *inconclusive*
     * result rejects unless {@see CertificateConfig::$revocationSoftFail} allows
     * it through.
     */
    private function assertNotRevoked(string $pemCertificate, string $serialNumber): void
    {
        if (!$this->config->checkRevocation || $this->revocationChecker === null) {
            return;
        }

        $bundle = $this->config->trustedCaBundlePath;

        if ($bundle === null || $bundle === '') {
            return;
        }

        $issuerPem = $this->issuerResolver->resolve($pemCertificate, $bundle);

        if ($issuerPem === null) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'psd2_certificate_revocation_skipped',
                metadata: ['serial_number' => $serialNumber],
            );

            return;
        }

        $status = $this->revocationChecker->check($pemCertificate, $issuerPem);

        if ($status === RevocationStatus::Revoked) {
            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'psd2_certificate_revoked',
                metadata: ['serial_number' => $serialNumber],
            );

            throw Psd2Exception::certificateRevoked($serialNumber);
        }

        if ($status === RevocationStatus::Unknown) {
            if ($this->config->revocationSoftFail) {
                $this->auditLogger?->log(
                    AuditEvent::SecurityEvent,
                    AuditOutcome::Failure,
                    null,
                    'psd2_certificate_revocation_soft_failed',
                    metadata: ['serial_number' => $serialNumber],
                );

                return;
            }

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                null,
                'psd2_certificate_revocation_unverified',
                metadata: ['serial_number' => $serialNumber],
            );

            throw Psd2Exception::revocationUnverified($serialNumber);
        }
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

}
