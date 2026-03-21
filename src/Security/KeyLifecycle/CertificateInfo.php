<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Information about a monitored certificate.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CertificateInfo
{
    public function __construct(
        public string $identifier,
        public string $subject,
        public string $issuer,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $notAfter,
        public string $serialNumber,
        public CertificateType $type,
    ) {}

    /**
     * Check if this certificate has expired.
     */
    #[NoDiscard]
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $now > $this->notAfter;
    }

    /**
     * Get the number of days until expiration.
     *
     * Returns negative values if already expired.
     */
    #[NoDiscard]
    public function daysUntilExpiry(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $diff = $now->diff($this->notAfter);

        $days = $diff->days !== false ? $diff->days : 0;

        return $diff->invert === 1 ? -$days : $days;
    }

    /**
     * Check if the certificate expires within the given number of days.
     */
    #[NoDiscard]
    public function expiresWithinDays(int $days, ?DateTimeImmutable $now = null): bool
    {
        return $this->daysUntilExpiry($now) <= $days;
    }

    /**
     * @return array{identifier: string, subject: string, issuer: string, not_before: string, not_after: string, serial_number: string, type: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'not_before' => $this->notBefore->format(DateTimeImmutable::ATOM),
            'not_after' => $this->notAfter->format(DateTimeImmutable::ATOM),
            'serial_number' => $this->serialNumber,
            'type' => $this->type->value,
        ];
    }
}
