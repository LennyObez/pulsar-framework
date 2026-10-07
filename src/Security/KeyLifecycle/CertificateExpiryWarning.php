<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Warning about an upcoming certificate expiration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CertificateExpiryWarning
{
    public function __construct(
        public CertificateInfo $certificate,
        public int $daysRemaining,
        public CertificateWarningLevel $level,
    ) {}

    /**
     * @return array{identifier: string, subject: string, days_remaining: int, level: string, expires_at: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'identifier' => $this->certificate->identifier,
            'subject' => $this->certificate->subject,
            'days_remaining' => $this->daysRemaining,
            'level' => $this->level->value,
            'expires_at' => $this->certificate->notAfter->format(DateTimeImmutable::ATOM),
        ];
    }
}
