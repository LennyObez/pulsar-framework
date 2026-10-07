<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Security\SafeHttpResponse;

#[CoversClass(SafeHttpResponse::class)]
final class SecurityEntitiesTest extends TestCase
{
    #[Test]
    public function safeHttpResponseConstructor(): void
    {
        $response = new SafeHttpResponse(
            statusCode: 200,
            headers: [
                'content-type' => ['application/json'],
                'x-request-id' => ['req-abc123'],
            ],
            body: '{"status":"ok"}',
            effectiveUrl: 'https://api.example.com/v1/data',
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame(['application/json'], $response->headers['content-type']);
        self::assertSame('{"status":"ok"}', $response->body);
        self::assertSame('https://api.example.com/v1/data', $response->effectiveUrl);
    }

    #[Test]
    public function safeHttpResponseRedirected(): void
    {
        $response = new SafeHttpResponse(
            statusCode: 301,
            headers: ['location' => ['https://new.example.com']],
            body: '',
            effectiveUrl: 'https://new.example.com',
        );

        self::assertSame(301, $response->statusCode);
        self::assertSame('https://new.example.com', $response->effectiveUrl);
    }
}
