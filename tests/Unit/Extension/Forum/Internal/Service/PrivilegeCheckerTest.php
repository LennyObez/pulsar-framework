<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ForumPrivilege;
use Pulsar\Extension\Forum\Internal\Service\PrivilegeChecker;
use Pulsar\Extension\Forum\Profile\ForumProfile;

#[CoversClass(PrivilegeChecker::class)]
final class PrivilegeCheckerTest extends TestCase
{
    private PrivilegeChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new PrivilegeChecker();
    }

    /**
     * @return iterable<string, array{ForumPrivilege, int}>
     */
    public static function providePrivilegeThresholds(): iterable
    {
        yield 'Upvote requires Contributor (10)' => [ForumPrivilege::Upvote, 10];
        yield 'Downvote requires Regular (50)' => [ForumPrivilege::Downvote, 50];
        yield 'BypassModQueue requires Trusted (100)' => [ForumPrivilege::BypassModQueue, 100];
        yield 'EditWikiPosts requires Veteran (250)' => [ForumPrivilege::EditWikiPosts, 250];
        yield 'CloseThreads requires Expert (500)' => [ForumPrivilege::CloseThreads, 500];
        yield 'AccessModTools requires Champion (1000)' => [ForumPrivilege::AccessModTools, 1000];
    }

    #[Test]
    #[DataProvider('providePrivilegeThresholds')]
    public function userAtExactThresholdIsAllowed(ForumPrivilege $privilege, int $threshold): void
    {
        $profile = self::profileWithReputation($threshold);

        self::assertTrue($this->checker->canPerform($privilege, $profile));
    }

    #[Test]
    #[DataProvider('providePrivilegeThresholds')]
    public function userAboveThresholdIsAllowed(ForumPrivilege $privilege, int $threshold): void
    {
        $profile = self::profileWithReputation($threshold + 1);

        self::assertTrue($this->checker->canPerform($privilege, $profile));
    }

    #[Test]
    #[DataProvider('providePrivilegeThresholds')]
    public function userBelowThresholdIsDenied(ForumPrivilege $privilege, int $threshold): void
    {
        $profile = self::profileWithReputation($threshold - 1);

        self::assertFalse($this->checker->canPerform($privilege, $profile));
    }

    #[Test]
    public function userWithZeroReputationIsDeniedAllPrivileges(): void
    {
        $profile = self::profileWithReputation(0);

        foreach (ForumPrivilege::cases() as $privilege) {
            self::assertFalse(
                $this->checker->canPerform($privilege, $profile),
                "Privilege {$privilege->value} should be denied for reputation 0",
            );
        }
    }

    #[Test]
    public function championScoreGrantsAllPrivileges(): void
    {
        $profile = self::profileWithReputation(1000);

        foreach (ForumPrivilege::cases() as $privilege) {
            self::assertTrue(
                $this->checker->canPerform($privilege, $profile),
                "Privilege {$privilege->value} should be granted for reputation 1000",
            );
        }
    }

    private static function profileWithReputation(int $score): ForumProfile
    {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-001',
            tenantId: null,
            userId: 'user-001',
            reputationScore: $score,
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
}
