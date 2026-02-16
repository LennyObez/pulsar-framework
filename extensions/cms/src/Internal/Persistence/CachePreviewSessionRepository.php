<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Themes\PreviewSession;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;

use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Cache-backed storage for theme preview sessions with automatic TTL expiry.
 */
#[Internal(reason: 'Cache-backed preview session storage')]
final readonly class CachePreviewSessionRepository implements PreviewSessionRepositoryInterface
{
    private const int TTL_SECONDS = 1800; // 30 minutes
    private const string KEY_PREFIX = 'cms_preview_session:';
    private const string TAG = 'cms_preview_sessions';

    public function __construct(
        private TaggedCacheInterface $cache,
    ) {}

    public function save(PreviewSession $session): void
    {
        $data = json_encode([
            'themeId' => $session->themeId,
            'token' => $session->token,
            'userId' => $session->userId,
            'expiresAt' => $session->expiresAt->format('c'),
        ], JSON_THROW_ON_ERROR);

        $this->cache->set(
            self::KEY_PREFIX . $session->token,
            $data,
            [self::TAG],
            self::TTL_SECONDS,
        );
    }

    public function findByToken(string $token): ?PreviewSession
    {
        $data = $this->cache->get(self::KEY_PREFIX . $token);

        if ($data === null) {
            return null;
        }

        /** @var array{themeId: string, token: string, userId: string, expiresAt: string} $decoded */
        $decoded = json_decode(is_string($data) ? $data : '', true, 512, JSON_THROW_ON_ERROR);

        $session = new PreviewSession(
            themeId: $decoded['themeId'],
            token: $decoded['token'],
            userId: $decoded['userId'],
            expiresAt: new DateTimeImmutable($decoded['expiresAt']),
        );

        return $session->isExpired() ? null : $session;
    }

    public function deleteExpired(): int
    {
        // Cache entries auto-expire via TTL; tag-based invalidation for bulk cleanup
        $this->cache->invalidateTag(self::TAG);

        return 0;
    }
}
