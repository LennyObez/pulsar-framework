<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivacyPassDirectoryClient;
use RuntimeException;

#[CoversClass(PrivacyPassDirectoryClient::class)]
final class PrivacyPassDirectoryClientTest extends TestCase
{
    private const string URL = 'https://issuer.example/.well-known/private-token-issuer-directory';
    private const string DIRECTORY = '{"token-keys":[{"token-type":2,"token-key":"keyA"},{"token-type":2,"token-key":"keyB"}]}';

    #[Test]
    public function refreshFetchesParsesAndCachesKeys(): void
    {
        $client = new PrivacyPassDirectoryClient($this->httpReturning(200, self::DIRECTORY), $this->cache());

        self::assertSame(['keyA', 'keyB'], $client->refresh(self::URL));
        self::assertSame(['keyA', 'keyB'], $client->cachedKeys(self::URL), 'keys are cached for later boots');
    }

    #[Test]
    public function cachedKeysAreEmptyWhenCold(): void
    {
        $client = new PrivacyPassDirectoryClient($this->httpReturning(200, self::DIRECTORY), $this->cache());

        self::assertSame([], $client->cachedKeys(self::URL));
    }

    #[Test]
    public function refreshKeepsStaleKeysOnNonSuccessStatus(): void
    {
        // The cache is the shared state; a fresh client with a failing transport
        // must still return the keys an earlier refresh primed.
        $cache = $this->cache();
        new PrivacyPassDirectoryClient($this->httpReturning(200, self::DIRECTORY), $cache)->refresh(self::URL);

        $client = new PrivacyPassDirectoryClient($this->httpReturning(503, 'upstream down'), $cache);
        self::assertSame(['keyA', 'keyB'], $client->refresh(self::URL), 'a 5xx keeps the previously cached keys');
    }

    #[Test]
    public function refreshKeepsStaleKeysWhenFetchThrows(): void
    {
        $cache = $this->cache();
        new PrivacyPassDirectoryClient($this->httpReturning(200, self::DIRECTORY), $cache)->refresh(self::URL);

        $client = new PrivacyPassDirectoryClient($this->httpThrowing(), $cache);
        self::assertSame(['keyA', 'keyB'], $client->refresh(self::URL), 'a network error keeps cached keys');
    }

    #[Test]
    public function refreshReturnsEmptyWhenDirectoryHasNoKeysAndCacheCold(): void
    {
        $client = new PrivacyPassDirectoryClient($this->httpReturning(200, '{"token-keys":[]}'), $this->cache());

        self::assertSame([], $client->refresh(self::URL));
    }

    private function httpReturning(int $status, string $body): HttpClientInterface
    {
        return new class ($status, $body) implements HttpClientInterface {
            public function __construct(
                private readonly int $status,
                private readonly string $body,
            ) {}

            public function get(string $url, array $options = []): HttpResponse
            {
                return new HttpResponse(ResponseStatus::from($this->status), new HeaderBag(), $this->body);
            }

            public function post(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function put(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function patch(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function delete(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function head(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function options(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }
        };
    }

    private function httpThrowing(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            public function get(string $url, array $options = []): HttpResponse
            {
                throw new RuntimeException('network error');
            }

            public function post(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function put(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function patch(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function delete(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function head(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function options(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }
        };
    }

    private function cache(): TaggedCacheInterface
    {
        return new class implements TaggedCacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void {}

            public function invalidateTags(array $tags): void {}
        };
    }
}
