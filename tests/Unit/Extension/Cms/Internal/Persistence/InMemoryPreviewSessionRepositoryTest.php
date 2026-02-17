<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Persistence\InMemoryPreviewSessionRepository;
use Pulsar\Extension\Cms\Themes\PreviewSession;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;

#[CoversClass(InMemoryPreviewSessionRepository::class)]
final class InMemoryPreviewSessionRepositoryTest extends TestCase
{
    private InMemoryPreviewSessionRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryPreviewSessionRepository();
    }

    #[Test]
    public function implementsPreviewSessionRepositoryInterface(): void
    {
        self::assertInstanceOf(PreviewSessionRepositoryInterface::class, $this->repository);
    }

    #[Test]
    public function saveAndFindByToken(): void
    {
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: 'tok-abc',
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->repository->save($session);
        $found = $this->repository->findByToken('tok-abc');

        self::assertNotNull($found);
        self::assertSame('theme-1', $found->themeId);
        self::assertSame('tok-abc', $found->token);
        self::assertSame('user-1', $found->userId);
    }

    #[Test]
    public function findByTokenReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->repository->findByToken('nonexistent'));
    }

    #[Test]
    public function findByTokenReturnsNullForExpiredSession(): void
    {
        $expired = new PreviewSession(
            themeId: 'theme-2',
            token: 'tok-expired',
            userId: 'user-2',
            expiresAt: new DateTimeImmutable('-1 minute'),
        );

        $this->repository->save($expired);

        self::assertNull($this->repository->findByToken('tok-expired'));
    }

    #[Test]
    public function deleteExpiredRemovesOnlyExpiredSessions(): void
    {
        $active = new PreviewSession(
            themeId: 'theme-a',
            token: 'tok-active',
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $expired = new PreviewSession(
            themeId: 'theme-b',
            token: 'tok-expired',
            userId: 'user-2',
            expiresAt: new DateTimeImmutable('-5 minutes'),
        );

        $this->repository->save($active);
        $this->repository->save($expired);

        $deletedCount = $this->repository->deleteExpired();

        self::assertSame(1, $deletedCount);
        self::assertNotNull($this->repository->findByToken('tok-active'));
        self::assertNull($this->repository->findByToken('tok-expired'));
    }

    #[Test]
    public function deleteExpiredReturnsZeroWhenNoneExpired(): void
    {
        $session = new PreviewSession(
            themeId: 'theme-c',
            token: 'tok-valid',
            userId: 'user-3',
            expiresAt: new DateTimeImmutable('+2 hours'),
        );

        $this->repository->save($session);

        self::assertSame(0, $this->repository->deleteExpired());
    }

    #[Test]
    public function saveOverwritesPreviousSessionWithSameToken(): void
    {
        $original = new PreviewSession(
            themeId: 'theme-old',
            token: 'tok-overwrite',
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $replacement = new PreviewSession(
            themeId: 'theme-new',
            token: 'tok-overwrite',
            userId: 'user-1',
            expiresAt: new DateTimeImmutable('+2 hours'),
        );

        $this->repository->save($original);
        $this->repository->save($replacement);

        $found = $this->repository->findByToken('tok-overwrite');

        self::assertNotNull($found);
        self::assertSame('theme-new', $found->themeId);
    }
}
