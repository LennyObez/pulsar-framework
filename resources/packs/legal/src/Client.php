<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Legal client entity.
 *
 * Represents a client of the law firm. Client data is confidential
 * and access is logged for audit purposes.
 */
final class Client
{
    /**
     * @param non-empty-string      $id           Unique client identifier
     * @param non-empty-string      $name         Client name (individual or organization)
     * @param non-empty-string      $clientType   Client type (e.g., "individual", "corporation")
     * @param non-empty-string|null $contactEmail Primary contact email
     * @param non-empty-string|null $contactPhone Primary contact phone
     * @param non-empty-string|null $address      Mailing address
     * @param ClientStatus          $status       Current client status
     * @param \DateTimeImmutable    $engagedAt    Engagement date
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $clientType,
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $address = null,
        public ClientStatus $status = ClientStatus::Active,
        public readonly \DateTimeImmutable $engagedAt = new \DateTimeImmutable(),
    ) {}

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }

    public function isOrganization(): bool
    {
        return $this->clientType === 'corporation' || $this->clientType === 'organization';
    }
}
