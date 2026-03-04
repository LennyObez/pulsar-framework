<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Account;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Account\ForumAccountSectionProvider;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

#[CoversClass(ForumAccountSectionProvider::class)]
final class ForumAccountSectionProviderTest extends TestCase
{
    #[Test]
    public function registersTwoSections(): void
    {
        $provider = $this->createProvider();

        $sections = $provider->getSections('user-1');

        self::assertCount(2, $sections);
        self::assertSame('forum-activity', $sections[0]->id);
        self::assertSame('badges', $sections[1]->id);
    }

    #[Test]
    public function forumActivitySectionHasPriority40(): void
    {
        $provider = $this->createProvider();

        $sections = $provider->getSections('user-1');

        self::assertSame(40, $sections[0]->priority);
    }

    #[Test]
    public function badgesSectionHasPriority45(): void
    {
        $provider = $this->createProvider();

        $sections = $provider->getSections('user-1');

        self::assertSame(45, $sections[1]->priority);
    }

    #[Test]
    public function renderFrontOfficeReturnsNonEmptyHtmlForActivity(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByAuthor')->willReturn(
            new PaginationResult([], 0, false, 10, null, null, null, 1, 1, new PaginationLinks()),
        );

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByAuthor')->willReturn(
            new PaginationResult([], 0, false, 10, null, null, null, 1, 1, new PaginationLinks()),
        );

        $provider = $this->createProvider($threadRepo, $postRepo);

        $html = $provider->renderFrontOffice('forum-activity', 'user-1');

        self::assertNotEmpty($html);
    }

    #[Test]
    public function renderFrontOfficeReturnsNonEmptyHtmlForBadges(): void
    {
        $provider = $this->createProvider();

        $html = $provider->renderFrontOffice('badges', 'user-1');

        self::assertNotEmpty($html);
    }

    #[Test]
    public function renderBackOfficeReturnsNonEmptyHtml(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByAuthor')->willReturn(
            new PaginationResult([], 0, false, 10, null, null, null, 1, 1, new PaginationLinks()),
        );

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByAuthor')->willReturn(
            new PaginationResult([], 0, false, 10, null, null, null, 1, 1, new PaginationLinks()),
        );

        $provider = $this->createProvider($threadRepo, $postRepo);

        $html = $provider->renderBackOffice('forum-activity', 'user-1');

        self::assertNotEmpty($html);
    }

    #[Test]
    public function renderUnknownSectionReturnsEmptyString(): void
    {
        $provider = $this->createProvider();

        $html = $provider->renderFrontOffice('nonexistent', 'user-1');

        self::assertSame('', $html);
    }

    private function createProvider(
        ?ThreadRepositoryInterface $threadRepo = null,
        ?PostRepositoryInterface $postRepo = null,
    ): ForumAccountSectionProvider {
        $threadRepo ??= $this->createStub(ThreadRepositoryInterface::class);
        $postRepo ??= $this->createStub(PostRepositoryInterface::class);
        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('getUserBadges')->willReturn([]);
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);

        return new ForumAccountSectionProvider(
            $threadRepo,
            $postRepo,
            $badgeService,
            $profileRepo,
        );
    }
}
