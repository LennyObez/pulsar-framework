<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Exception\ForumException;
use RuntimeException;

final class ForumExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new ForumException('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function notFoundContainsEntityAndId(): void
    {
        $e = ForumException::notFound('Thread', 'abc-123');

        self::assertStringContainsString('Thread', $e->getMessage());
        self::assertStringContainsString('abc-123', $e->getMessage());
    }

    #[Test]
    public function invalidTransitionContainsStates(): void
    {
        $e = ForumException::invalidTransition('open', 'closed');

        self::assertStringContainsString('open', $e->getMessage());
        self::assertStringContainsString('closed', $e->getMessage());
    }

    #[Test]
    public function concurrencyConflictContainsDetails(): void
    {
        $e = ForumException::concurrencyConflict('entity-1', 5);

        self::assertStringContainsString('entity-1', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
    }

    #[Test]
    public function unauthorizedContainsAction(): void
    {
        $e = ForumException::unauthorized('delete_thread');

        self::assertStringContainsString('delete_thread', $e->getMessage());
    }

    #[Test]
    public function bannedContainsUserId(): void
    {
        $e = ForumException::banned('user-42');

        self::assertStringContainsString('user-42', $e->getMessage());
    }

    #[Test]
    public function rateLimitedContainsDetails(): void
    {
        $e = ForumException::rateLimited('post', 30);

        self::assertStringContainsString('post', $e->getMessage());
        self::assertStringContainsString('30', $e->getMessage());
    }

    #[Test]
    public function duplicateVoteContainsDetails(): void
    {
        $e = ForumException::duplicateVote('user-1', 'post-1');

        self::assertStringContainsString('user-1', $e->getMessage());
        self::assertStringContainsString('post-1', $e->getMessage());
    }

    #[Test]
    public function alreadyResolvedContainsThreadId(): void
    {
        $e = ForumException::alreadyResolved('thread-1');

        self::assertStringContainsString('thread-1', $e->getMessage());
    }

    #[Test]
    public function editWindowExpiredContainsPostId(): void
    {
        $e = ForumException::editWindowExpired('post-1');

        self::assertStringContainsString('post-1', $e->getMessage());
    }

    #[Test]
    public function threadLockedContainsThreadId(): void
    {
        $e = ForumException::threadLocked('thread-1');

        self::assertStringContainsString('thread-1', $e->getMessage());
    }

    #[Test]
    public function categoryLockedContainsCategoryId(): void
    {
        $e = ForumException::categoryLocked('cat-1');

        self::assertStringContainsString('cat-1', $e->getMessage());
    }

    #[Test]
    public function selfVoteHasMessage(): void
    {
        $e = ForumException::selfVote();

        self::assertStringContainsString('own content', $e->getMessage());
    }

    #[Test]
    public function insufficientReputationContainsRequired(): void
    {
        $e = ForumException::insufficientReputation(50);

        self::assertStringContainsString('50', $e->getMessage());
    }

    #[Test]
    public function duplicateReportContainsDetails(): void
    {
        $e = ForumException::duplicateReport('user-1', 'post-1');

        self::assertStringContainsString('user-1', $e->getMessage());
        self::assertStringContainsString('post-1', $e->getMessage());
    }
}
