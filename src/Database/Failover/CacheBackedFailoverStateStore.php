<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;

use function is_string;

/**
 * PSR-16-cache-backed {@see FailoverStateStore}.
 *
 * The configured cache store (filesystem, redis, …) is shared across the
 * watcher daemon and the web/worker processes, so a failover recorded by the
 * daemon is immediately visible everywhere. The key is a fixed, PSR-6/PSR-16
 * reserved-character-free string.
 */
#[Internal]
final readonly class CacheBackedFailoverStateStore implements FailoverStateStore
{
    private const string CACHE_KEY = 'pulsar_db_failover_endpoint';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    #[Override]
    public function currentEndpoint(): ?string
    {
        /** @var mixed $value */
        $value = $this->cache->get(self::CACHE_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    #[Override]
    public function recordFailover(string $endpoint): void
    {
        if ($endpoint === '') {
            return;
        }

        $this->cache->set(self::CACHE_KEY, $endpoint);
    }

    #[Override]
    public function clear(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
