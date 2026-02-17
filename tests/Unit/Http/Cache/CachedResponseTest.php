<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Cache\CachedResponse;

#[CoversClass(CachedResponse::class)]
final class CachedResponseTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $response = new CachedResponse(
            statusCode: 200,
            headers: ['Content-Type' => ['text/html']],
            body: '<h1>Hello</h1>',
            etag: '"abc123"',
            createdAt: 1000,
            expiresAt: 2000,
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame(['Content-Type' => ['text/html']], $response->headers);
        self::assertSame('<h1>Hello</h1>', $response->body);
        self::assertSame('"abc123"', $response->etag);
        self::assertSame(1000, $response->createdAt);
        self::assertSame(2000, $response->expiresAt);
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiresAt(): void
    {
        $response = new CachedResponse(
            statusCode: 200,
            headers: [],
            body: '',
            etag: '',
            createdAt: 1000,
            expiresAt: 1, // Already expired
        );

        self::assertTrue($response->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenStillValid(): void
    {
        $response = new CachedResponse(
            statusCode: 200,
            headers: [],
            body: '',
            etag: '',
            createdAt: time(),
            expiresAt: time() + 3600,
        );

        self::assertFalse($response->isExpired());
    }

    #[Test]
    public function remainingTtlReturnsPositiveValueForValidEntry(): void
    {
        $response = new CachedResponse(
            statusCode: 200,
            headers: [],
            body: '',
            etag: '',
            createdAt: time(),
            expiresAt: time() + 100,
        );

        $ttl = $response->remainingTtl();

        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(100, $ttl);
    }

    #[Test]
    public function remainingTtlReturnsZeroForExpiredEntry(): void
    {
        $response = new CachedResponse(
            statusCode: 200,
            headers: [],
            body: '',
            etag: '',
            createdAt: 1000,
            expiresAt: 1, // Already expired
        );

        self::assertSame(0, $response->remainingTtl());
    }
}
