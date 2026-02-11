<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\MailHttpResponse;

#[CoversClass(MailHttpResponse::class)]
final class MailHttpResponseTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $response = new MailHttpResponse(
            statusCode: 200,
            body: '{"id":"msg-12345","status":"queued"}',
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"id":"msg-12345","status":"queued"}', $response->body);
    }

    #[Test]
    public function errorResponse(): void
    {
        $response = new MailHttpResponse(
            statusCode: 429,
            body: '{"error":"Rate limit exceeded"}',
        );

        self::assertSame(429, $response->statusCode);
    }
}
