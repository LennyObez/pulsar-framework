<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;

use function array_filter;
use function array_values;

/**
 * @internal Stub badge service for E2E tests.
 */
final class E2EBadgeService implements BadgeServiceInterface
{
    /** @var array<string, list<Badge>> */
    private array $badges = [];

    #[Override]
    public function evaluate(string $userId, Badge $badge): bool
    {
        return true;
    }

    #[Override]
    public function award(string $userId, Badge $badge, ?string $tenantId = null): ?UserBadge
    {
        if ($this->hasBadge($userId, $badge)) {
            return null;
        }

        $this->badges[$userId][] = $badge;

        return UserBadge::award(
            id: 'badge-' . $userId . '-' . $badge->value,
            userId: $userId,
            badge: $badge,
            tenantId: $tenantId,
        );
    }

    #[Override]
    public function revoke(string $userId, Badge $badge): void
    {
        if (!isset($this->badges[$userId])) {
            return;
        }

        $this->badges[$userId] = array_values(array_filter(
            $this->badges[$userId],
            static fn(Badge $b) => $b !== $badge,
        ));
    }

    #[Override]
    public function getUserBadges(string $userId): array
    {
        return [];
    }

    #[Override]
    public function hasBadge(string $userId, Badge $badge): bool
    {
        if (!isset($this->badges[$userId])) {
            return false;
        }

        foreach ($this->badges[$userId] as $b) {
            if ($b === $badge) {
                return true;
            }
        }

        return false;
    }
}
