<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Persistence\CachePreviewSessionRepository;
use Pulsar\Extension\Cms\Themes\PreviewSession;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CachePreviewSessionRepository::class)]
final class PreviewSessionRepositoryTest extends TestCase
{
    private TaggedCacheInterface&Stub $cache;
    private CachePreviewSessionRepository $repository;

    protected function setUp(): void
    {
        $this->cache = $this->createStub(TaggedCacheInterface::class);
        $this->repository = new CachePreviewSessionRepository($this->cache);
    }

    #[Test]
    public function save_and_find_roundtrip(): void
    {
        $expiresAt = new DateTimeImmutable('+30 minutes');
        $session = new PreviewSession(
            themeId: 'theme-abc',
            token: 'tok-123',
            userId: 'user-42',
            expiresAt: $expiresAt,
        );

        $stored = null;

        $this->cache = $this->createStub(TaggedCacheInterface::class);
        $this->cache->method('set')->willReturnCallback(
            function (string $key, mixed $value) use (&$stored): bool {
                $stored = ['key' => $key, 'value' => $value];

                return true;
            },
        );
        $this->cache->method('get')->willReturnCallback(
            function (string $key) use (&$stored): mixed {
                return ($stored !== null && $stored['key'] === $key) ? $stored['value'] : null;
            },
        );

        $repo = new CachePreviewSessionRepository($this->cache);
        $repo->save($session);

        $found = $repo->findByToken('tok-123');

        self::assertNotNull($found);
        self::assertSame('theme-abc', $found->themeId);
        self::assertSame('tok-123', $found->token);
        self::assertSame('user-42', $found->userId);
        self::assertSame($expiresAt->format('c'), $found->expiresAt->format('c'));
    }

    #[Test]
    public function find_by_token_returns_null_for_missing_key(): void
    {
        $this->cache->method('get')->willReturn(null);

        self::assertNull($this->repository->findByToken('nonexistent'));
    }

    #[Test]
    public function find_by_token_returns_null_for_expired_session(): void
    {
        $data = json_encode([
            'themeId' => 'theme-abc',
            'token' => 'tok-expired',
            'userId' => 'user-42',
            'expiresAt' => new DateTimeImmutable('-5 minutes')->format('c'),
        ], JSON_THROW_ON_ERROR);

        $this->cache->method('get')->willReturn($data);

        self::assertNull($this->repository->findByToken('tok-expired'));
    }

    #[Test]
    public function delete_expired_invalidates_tag(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_preview_sessions');

        $repo = new CachePreviewSessionRepository($cache);

        self::assertSame(0, $repo->deleteExpired());
    }
}
