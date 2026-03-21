<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Profile;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Exception\ForumException;

/**
 * Forum profile: per-user forum metadata including reputation, activity counts, and ban state.
 *
 * Linked to the shared auth_users table via userId. Each user has at most
 * one forum profile per tenant.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumProfile
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $userId UUIDv7 FK auth_users
     * @param int $reputationScore Cumulative reputation score
     * @param int $postCount Denormalized total post count
     * @param int $threadCount Denormalized total thread count
     * @param bool $isBanned Whether the user is banned from the forum
     * @param string|null $banReason Reason for the ban
     * @param DateTimeImmutable|null $bannedAt When the ban was applied
     * @param DateTimeImmutable|null $banExpiresAt When the ban expires (null = permanent)
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $userId,
        public int $reputationScore,
        public int $postCount,
        public int $threadCount,
        public bool $isBanned,
        public ?string $banReason,
        public ?DateTimeImmutable $bannedAt,
        public ?DateTimeImmutable $banExpiresAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new forum profile for a user.
     */
    public static function create(
        string $id,
        string $userId,
        ?string $tenantId = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Add reputation points (positive or negative).
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function addReputation(int $points): self
    {
        return clone($this, [
            'reputationScore' => $this->reputationScore + $points,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Increment the post count.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function incrementPostCount(): self
    {
        return clone($this, [
            'postCount' => $this->postCount + 1,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Increment the thread count.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function incrementThreadCount(): self
    {
        return clone($this, [
            'threadCount' => $this->threadCount + 1,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Ban the user from the forum.
     *
     * @throws ForumException If the user is already banned
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function ban(string $reason, ?DateTimeImmutable $expiresAt = null): self
    {
        if ($this->isBanned) {
            throw ForumException::banned($this->userId);
        }

        return clone($this, [
            'isBanned' => true,
            'banReason' => $reason,
            'bannedAt' => new DateTimeImmutable(),
            'banExpiresAt' => $expiresAt,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Remove the ban from the user.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function unban(): self
    {
        return clone($this, [
            'isBanned' => false,
            'banReason' => null,
            'bannedAt' => null,
            'banExpiresAt' => null,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Get the current reputation level based on the score.
     */
    public function reputationLevel(): ReputationLevel
    {
        return ReputationLevel::fromScore($this->reputationScore);
    }

    /**
     * Whether the ban has expired (returns false if not banned or permanent).
     */
    public function isBanExpired(): bool
    {
        if (!$this->isBanned || $this->banExpiresAt === null) {
            return false;
        }

        return new DateTimeImmutable() >= $this->banExpiresAt;
    }
}
