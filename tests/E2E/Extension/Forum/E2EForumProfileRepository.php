<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;

/**
 * @internal In-memory forum profile repository for E2E tests.
 */
final class E2EForumProfileRepository implements ForumProfileRepositoryInterface
{
    /** @var array<string, ForumProfile> */
    private array $profiles = [];

    #[Override]
    public function findById(string $id): ?ForumProfile
    {
        return $this->profiles[$id] ?? null;
    }

    #[Override]
    public function findByUser(string $userId, ?string $tenantId = null): ?ForumProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->userId === $userId) {
                return $profile;
            }
        }

        return null;
    }

    #[Override]
    public function findTopContributors(int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
    {
        return new PaginationResult(items: [], total: 0, hasMore: false, perPage: $perPage);
    }

    #[Override]
    public function save(ForumProfile $profile): void
    {
        $this->profiles[$profile->id] = $profile;
    }

    #[Override]
    public function delete(ForumProfile $profile): void
    {
        unset($this->profiles[$profile->id]);
    }

    #[Override]
    public function incrementReputation(string $userId, int $delta, ?string $tenantId = null): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null) {
            $this->profiles[$profile->id] = $profile->addReputation($delta);
        }
    }

    #[Override]
    public function incrementPostCount(string $userId, ?string $tenantId = null, int $delta = 1): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null && $delta > 0) {
            for ($i = 0; $i < $delta; $i++) {
                $this->profiles[$profile->id] = $this->profiles[$profile->id]->incrementPostCount();
            }
        }
    }

    #[Override]
    public function incrementThreadCount(string $userId, ?string $tenantId = null, int $delta = 1): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null && $delta > 0) {
            for ($i = 0; $i < $delta; $i++) {
                $this->profiles[$profile->id] = $this->profiles[$profile->id]->incrementThreadCount();
            }
        }
    }

    #[Override]
    public function clearBanFlag(string $userId, ?string $tenantId = null): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null) {
            $this->profiles[$profile->id] = $profile->unban();
        }
    }
}
