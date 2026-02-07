<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Users;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Auth\Identity\TwoFactorStatus;

/**
 * CMS user projection — read-only view of a user with CMS-relevant fields.
 *
 * This is not the canonical user entity (that lives in Auth). It is a
 * projection tailored for CMS admin user-management screens: roles, 2FA
 * status, content counts, and last-activity timestamps.
 */
#[Api(since: '1.0.0')]
final readonly class CmsUser
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $displayName Human-readable display name
     * @param string|null $email User email address
     * @param list<string> $roles CMS role names assigned to this user
     * @param TwoFactorStatus $twoFactorStatus Current 2FA enrollment status
     * @param int $contentCount Number of content items authored
     * @param int $commentCount Number of comments authored
     * @param DateTimeImmutable|null $lastActiveAt Last activity timestamp
     * @param DateTimeImmutable $createdAt Account creation timestamp
     * @param bool $isLocked Whether the account is locked
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $displayName,
        public ?string $email,
        public array $roles,
        public TwoFactorStatus $twoFactorStatus,
        public int $contentCount,
        public int $commentCount,
        public ?DateTimeImmutable $lastActiveAt,
        public DateTimeImmutable $createdAt,
        public bool $isLocked,
    ) {}
}
