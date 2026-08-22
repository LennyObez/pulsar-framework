<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Customer account linking commerce data to the shared auth_users table.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Customer
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId Tenant scope, null when tenancy disabled
     * @param string|null $userId Link to auth_users table
     * @param string $email Customer email address
     * @param string|null $displayName Customer display name
     * @param array<string, mixed>|null $billingAddress Structured billing address
     * @param array<string, mixed>|null $shippingAddress Structured shipping address
     * @param string|null $notes Admin notes (timestamped, append-only)
     * @param DateTimeImmutable $createdAt When customer was first created
     * @param DateTimeImmutable $updatedAt Last update timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ?string $userId,
        public string $email,
        public ?string $displayName,
        public ?array $billingAddress,
        public ?array $shippingAddress,
        public ?string $notes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function hasLinkedUser(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Return a copy with updated profile fields and a refreshed update timestamp.
     *
     * @param array<string, mixed>|null $billingAddress Structured billing address
     * @param array<string, mixed>|null $shippingAddress Structured shipping address
     */
    public function withProfile(
        ?string $displayName,
        ?array $billingAddress,
        ?array $shippingAddress,
    ): self {
        return clone($this, [
            'displayName' => $displayName,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Return a copy with replaced admin notes and a refreshed update timestamp.
     */
    public function withNotes(?string $notes): self
    {
        return clone($this, [
            'notes' => $notes,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }
}
