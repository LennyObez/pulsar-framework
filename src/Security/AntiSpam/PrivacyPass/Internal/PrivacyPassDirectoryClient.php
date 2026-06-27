<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass\Internal;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Http\Client\HttpClientInterface;
use Throwable;

use function array_filter;
use function array_values;
use function hash;
use function is_array;
use function is_string;

/**
 * Fetches and caches a Privacy Pass issuer's token keys from its directory
 * (RFC 9576). Keys are cached so the request path never blocks on the network:
 * {@see self::refresh()} (run from the CLI/scheduler) populates the cache, and
 * {@see self::cachedKeys()} reads it at boot.
 *
 * Resilient by design: a failed or non-200 refresh keeps the previously cached
 * keys rather than dropping trust on a transient outage.
 */
#[Internal]
final readonly class PrivacyPassDirectoryClient
{
    private const string CACHE_TAG = 'privacy-pass-directory';

    public function __construct(
        private HttpClientInterface $http,
        private TaggedCacheInterface $cache,
        private int $cacheTtlSeconds = 86400,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Fetch the directory, cache the type-0x0002 keys, and return them. On any
     * failure the previously cached keys are returned (stale-while-erroring).
     *
     * @return list<string> base64url SPKI keys
     */
    public function refresh(string $directoryUrl): array
    {
        try {
            $response = $this->http->get($directoryUrl);

            if (!$response->ok()) {
                $this->logger?->warning('Privacy Pass directory fetch returned a non-success status; keeping cached keys.', [
                    'url' => $directoryUrl,
                    'status' => $response->status(),
                ]);

                return $this->cachedKeys($directoryUrl);
            }

            $keys = PrivacyPassDirectory::parseKeys($response->body());

            if ($keys === []) {
                $this->logger?->warning('Privacy Pass directory contained no usable token keys; keeping cached keys.', [
                    'url' => $directoryUrl,
                ]);

                return $this->cachedKeys($directoryUrl);
            }

            $this->cache->set($this->cacheKey($directoryUrl), $keys, [self::CACHE_TAG], $this->cacheTtlSeconds);

            return $keys;
        } catch (Throwable $e) {
            $this->logger?->warning('Privacy Pass directory fetch failed; keeping cached keys.', [
                'url' => $directoryUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->cachedKeys($directoryUrl);
        }
    }

    /**
     * Previously cached directory keys, or [] when the cache is cold.
     *
     * @return list<string>
     */
    public function cachedKeys(string $directoryUrl): array
    {
        /** @var mixed $cached */
        $cached = $this->cache->get($this->cacheKey($directoryUrl));

        if (!is_array($cached)) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = array_values(array_filter($cached, is_string(...)));

        return $keys;
    }

    private function cacheKey(string $directoryUrl): string
    {
        return 'privacy-pass.directory.' . hash('sha256', $directoryUrl);
    }
}
