<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Support\UuidGenerator;

/**
 * Records a ban imposed on a forum user.
 *
 * Supports temporary (with expiry) and permanent bans. A ban can be revoked
 * by a moderator, which sets revokedAt without deleting the record for audit
 * purposes.
 */
#[Api(since: '1.0.0')]
final readonly class UserBan
{
    /**
     * @param string $id UUIDv7
     * @param string $userId UUIDv7 FK auth_users: the banned user
     * @param string $bannedBy UUIDv7 FK auth_users: the moderator who imposed the ban
     * @param string $reason Moderator-provided justification
     * @param BanType $type Whether the ban is temporary or permanent
     * @param DateTimeImmutable|null $expiresAt When the ban expires (null for permanent)
     * @param DateTimeImmutable $createdAt When the ban was imposed
     * @param DateTimeImmutable|null $revokedAt When the ban was revoked (null if still active)
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $bannedBy,
        public string $reason,
        public BanType $type,
        public ?DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $revokedAt,
    ) {}

    /**
     * Create a new user ban record.
     */
    public static function create(
        string $userId,
        string $bannedBy,
        string $reason,
        BanType $type,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(
            id: UuidGenerator::v7(),
            userId: $userId,
            bannedBy: $bannedBy,
            reason: $reason,
            type: $type,
            expiresAt: $expiresAt,
            createdAt: new DateTimeImmutable(),
            revokedAt: null,
        );
    }

    /**
     * Revoke this ban, marking it as no longer active.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function revoke(): self
    {
        return clone($this, [
            'revokedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Whether this ban is currently active.
     *
     * A ban is active when it has not been revoked and either:
     * - it is permanent (no expiry), or
     * - it has not yet expired.
     */
    public function isActive(): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        if ($this->type === BanType::Permanent) {
            return true;
        }

        if ($this->expiresAt === null) {
            return true;
        }

        return new DateTimeImmutable() < $this->expiresAt;
    }
}
