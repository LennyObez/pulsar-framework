<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\ManagedChallenge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRefreshController;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;

use function json_decode;

#[CoversClass(ManagedChallengeRefreshController::class)]
final class ManagedChallengeRefreshControllerTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes

    private function service(): ManagedChallengeService
    {
        return new ManagedChallengeService(self::KEY, 8, 300);
    }

    private function request(string $ip = '203.0.113.5'): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return $request;
    }

    #[Test]
    public function refreshReturnsAFreshlySignedChallengeAsJson(): void
    {
        $service = $this->service();
        $controller = new ManagedChallengeRefreshController($service);

        $response = $controller->refresh($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        /** @var mixed $payload */
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('challenge', $payload);
        self::assertArrayHasKey('id', $payload);
        self::assertArrayHasKey('bits', $payload);

        // The minted token must verify against the same service — a fresh, valid
        // challenge the widget can solve and submit within its own TTL.
        self::assertIsString($payload['challenge']);
        $parsed = $service->parse($payload['challenge']);
        self::assertNotNull($parsed);
        self::assertSame($payload['id'], $parsed->id);
        self::assertSame($payload['bits'], $parsed->bits);
    }

    #[Test]
    public function refreshIsRateLimitedPerIpWhenACacheIsAvailable(): void
    {
        $cache = $this->inMemoryCache();
        $controller = new ManagedChallengeRefreshController($this->service(), $cache);
        $request = $this->request('198.51.100.7');

        // 30 are allowed within the minute window…
        for ($i = 0; $i < 30; $i++) {
            self::assertSame(200, $controller->refresh($request)->getStatusCode());
        }

        // …the 31st is capped.
        $capped = $controller->refresh($request);
        self::assertSame(429, $capped->getStatusCode());
        self::assertNotEmpty($capped->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function refreshIsNotRateLimitedWithoutACache(): void
    {
        $controller = new ManagedChallengeRefreshController($this->service());
        $request = $this->request('192.0.2.9');

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(200, $controller->refresh($request)->getStatusCode());
        }
    }

    private function inMemoryCache(): TaggedCacheInterface
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

            public function invalidateTag(string $tag): void
            {
                $this->store = [];
            }

            public function invalidateTags(array $tags): void
            {
                $this->store = [];
            }
        };
    }
}
