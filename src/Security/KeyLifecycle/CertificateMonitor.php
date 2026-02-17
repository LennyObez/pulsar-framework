<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function array_values;
use function sprintf;

/**
 * Monitors certificate expiration and emits warnings.
 *
 * Tracks TLS, signing, and FIPS module certificates with configurable
 * warning thresholds. Integrates with deploy checks to prevent deploys
 * with nearly-expired certificates.
 */
#[Api(since: '1.0.0')]
final class CertificateMonitor
{
    /** @var array<string, CertificateInfo> identifier => info */
    private array $certificates = [];

    /**
     * @param list<int> $warningThresholdDays Days before expiry to trigger warnings (descending order)
     */
    public function __construct(
        private readonly array $warningThresholdDays = [30, 14, 7, 1],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Register a certificate for monitoring.
     */
    public function register(CertificateInfo $certificate): void
    {
        $this->certificates[$certificate->identifier] = $certificate;

        $this->logger?->info(sprintf(
            'Certificate registered for monitoring: %s (expires: %s)',
            $certificate->identifier,
            $certificate->notAfter->format('Y-m-d'),
        ));
    }

    /**
     * Remove a certificate from monitoring.
     */
    public function unregister(string $identifier): void
    {
        unset($this->certificates[$identifier]);
    }

    /**
     * Check all certificates and return any expiry warnings.
     *
     * @return list<CertificateExpiryWarning>
     */
    #[NoDiscard]
    public function check(?DateTimeImmutable $now = null): array
    {
        $warnings = [];

        foreach ($this->certificates as $cert) {
            $warning = $this->evaluateCertificate($cert, $now);

            if ($warning !== null) {
                $warnings[] = $warning;
                $this->logWarning($warning);
            }
        }

        return $warnings;
    }

    /**
     * Check if any certificate has expired.
     */
    #[NoDiscard]
    public function hasExpiredCertificates(?DateTimeImmutable $now = null): bool
    {
        foreach ($this->certificates as $cert) {
            if ($cert->isExpired($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if deployment is safe (no expired or critically near-expiry certificates).
     */
    #[NoDiscard]
    public function isDeploySafe(int $minimumDaysRemaining = 1, ?DateTimeImmutable $now = null): bool
    {
        foreach ($this->certificates as $cert) {
            if ($cert->daysUntilExpiry($now) < $minimumDaysRemaining) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all registered certificates.
     *
     * @return list<CertificateInfo>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->certificates);
    }

    /**
     * Get a specific certificate.
     */
    #[NoDiscard]
    public function find(string $identifier): ?CertificateInfo
    {
        return $this->certificates[$identifier] ?? null;
    }

    private function evaluateCertificate(CertificateInfo $cert, ?DateTimeImmutable $now): ?CertificateExpiryWarning
    {
        $daysRemaining = $cert->daysUntilExpiry($now);

        if ($daysRemaining < 0 || $cert->isExpired($now)) {
            return new CertificateExpiryWarning($cert, $daysRemaining, CertificateWarningLevel::Expired);
        }

        foreach ($this->warningThresholdDays as $threshold) {
            if ($daysRemaining <= $threshold) {
                $level = match (true) {
                    $threshold <= 1 => CertificateWarningLevel::Critical,
                    $threshold <= 7 => CertificateWarningLevel::Warning,
                    default => CertificateWarningLevel::Info,
                };

                return new CertificateExpiryWarning($cert, $daysRemaining, $level);
            }
        }

        return null;
    }

    private function logWarning(CertificateExpiryWarning $warning): void
    {
        $message = sprintf(
            'Certificate %s expires in %d days (level: %s)',
            $warning->certificate->identifier,
            $warning->daysRemaining,
            $warning->level->value,
        );

        match ($warning->level) {
            CertificateWarningLevel::Expired,
            CertificateWarningLevel::Critical => $this->logger?->critical($message),
            CertificateWarningLevel::Warning => $this->logger?->warning($message),
            CertificateWarningLevel::Info => $this->logger?->info($message),
        };
    }
}
