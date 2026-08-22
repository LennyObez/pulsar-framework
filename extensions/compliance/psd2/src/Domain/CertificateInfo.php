<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Parsed information from a PSD2 eIDAS certificate (QWAC/QSEAL).
 *
 * Extracts PSD2-specific fields from the certificate's
 * QcStatements extension (ETSI TS 119 495).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CertificateInfo
{
    /**
     * @param list<string> $psd2Roles PSP roles from QcStatement (e.g., PSP_AI, PSP_PI, PSP_AS, PSP_IC)
     */
    public function __construct(
        public CertificateType $type,
        public string $subject,
        public string $issuer,
        public string $serialNumber,
        public string $authorizationNumber,
        public array $psd2Roles,
        public string $ncaName,
        public string $ncaId,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validUntil,
        public bool $isQualified,
    ) {}

    /**
     * Check whether this certificate is currently valid.
     */
    public function isValid(DateTimeImmutable $now): bool
    {
        return $now >= $this->validFrom && $now <= $this->validUntil;
    }

    /**
     * Check whether the certificate holder has a specific PSD2 role.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->psd2Roles, true);
    }

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'serial_number' => $this->serialNumber,
            'authorization_number' => $this->authorizationNumber,
            'psd2_roles' => $this->psd2Roles,
            'nca_name' => $this->ncaName,
            'nca_id' => $this->ncaId,
            'valid_from' => $this->validFrom->format('Y-m-d\TH:i:s.uP'),
            'valid_until' => $this->validUntil->format('Y-m-d\TH:i:s.uP'),
            'is_qualified' => $this->isQualified,
        ];
    }
}
