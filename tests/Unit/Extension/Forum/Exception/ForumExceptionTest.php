<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Exception\ForumException;
use RuntimeException;

#[CoversClass(ForumException::class)]
final class ForumExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new ForumException('test');
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function notFound(): void
    {
        $e = ForumException::notFound('Thread', 'abc-123');
        self::assertStringContainsString('Thread', $e->getMessage());
        self::assertStringContainsString('abc-123', $e->getMessage());
    }

    #[Test]
    public function invalidTransition(): void
    {
        $e = ForumException::invalidTransition('open', 'open');
        self::assertStringContainsString('open', $e->getMessage());
    }

    #[Test]
    public function concurrencyConflict(): void
    {
        $e = ForumException::concurrencyConflict('entity-1', 3);
        self::assertStringContainsString('entity-1', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    #[Test]
    public function unauthorized(): void
    {
        $e = ForumException::unauthorized('delete_thread');
        self::assertStringContainsString('delete_thread', $e->getMessage());
    }

    #[Test]
    public function banned(): void
    {
        $e = ForumException::banned('user-1');
        self::assertStringContainsString('user-1', $e->getMessage());
    }

    #[Test]
    public function rateLimited(): void
    {
        $e = ForumException::rateLimited('post_create', 30);
        self::assertStringContainsString('post_create', $e->getMessage());
        self::assertStringContainsString('30', $e->getMessage());
    }

    #[Test]
    public function duplicateVote(): void
    {
        $e = ForumException::duplicateVote('user-1', 'post-1');
        self::assertStringContainsString('user-1', $e->getMessage());
        self::assertStringContainsString('post-1', $e->getMessage());
    }

    #[Test]
    public function alreadyResolved(): void
    {
        $e = ForumException::alreadyResolved('thread-1');
        self::assertStringContainsString('thread-1', $e->getMessage());
    }

    #[Test]
    public function editWindowExpired(): void
    {
        $e = ForumException::editWindowExpired('post-1');
        self::assertStringContainsString('post-1', $e->getMessage());
    }

    #[Test]
    public function threadLocked(): void
    {
        $e = ForumException::threadLocked('thread-1');
        self::assertStringContainsString('thread-1', $e->getMessage());
    }

    #[Test]
    public function categoryLocked(): void
    {
        $e = ForumException::categoryLocked('cat-1');
        self::assertStringContainsString('cat-1', $e->getMessage());
    }
}
