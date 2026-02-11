<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Know Your Customer (KYC) profile entity.
 *
 * Tracks identity verification status for regulatory compliance.
 * All KYC data access is logged for audit purposes.
 */
final class KycProfile
{
    /**
     * @param non-empty-string        $id               Unique profile identifier
     * @param non-empty-string        $accountId        Associated account identifier
     * @param non-empty-string        $fullName         Full legal name
     * @param non-empty-string|null   $documentType     ID document type (e.g., "passport", "drivers_license")
     * @param non-empty-string|null   $documentNumber   Encrypted document number
     * @param KycVerificationStatus   $status           Current verification status
     * @param non-empty-string|null   $verifiedBy       Identifier of the verifying agent or system
     * @param \DateTimeImmutable      $submittedAt      Submission timestamp
     * @param \DateTimeImmutable|null $verifiedAt       Verification completion timestamp
     * @param \DateTimeImmutable|null $expiresAt        Verification expiration timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $accountId,
        public readonly string $fullName,
        public readonly ?string $documentType = null,
        public readonly ?string $documentNumber = null,
        public KycVerificationStatus $status = KycVerificationStatus::Pending,
        public readonly ?string $verifiedBy = null,
        public readonly \DateTimeImmutable $submittedAt = new \DateTimeImmutable(),
        public readonly ?\DateTimeImmutable $verifiedAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {}

    public function isVerified(): bool
    {
        return $this->status === KycVerificationStatus::Verified;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function needsReverification(): bool
    {
        return $this->isExpired() || $this->status === KycVerificationStatus::Expired;
    }
}
