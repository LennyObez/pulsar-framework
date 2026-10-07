<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\WebhookPayload;

#[CoversClass(WebhookPayload::class)]
final class WebhookPayloadExtendedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            data: ['event' => 'order.created', 'order_id' => 42],
            headers: ['X-Custom' => 'value'],
            method: 'PUT',
        );

        self::assertSame('https://example.com/webhook', $payload->url);
        self::assertSame(['event' => 'order.created', 'order_id' => 42], $payload->data);
        self::assertSame(['X-Custom' => 'value'], $payload->headers);
        self::assertSame('PUT', $payload->method);
    }

    #[Test]
    public function defaultMethodIsPost(): void
    {
        $payload = new WebhookPayload(url: 'https://example.com/hook');

        self::assertSame('POST', $payload->method);
    }

    #[Test]
    public function defaultDataIsEmptyArray(): void
    {
        $payload = new WebhookPayload(url: 'https://example.com/hook');

        self::assertSame([], $payload->data);
    }

    #[Test]
    public function defaultHeadersIsEmptyArray(): void
    {
        $payload = new WebhookPayload(url: 'https://example.com/hook');

        self::assertSame([], $payload->headers);
    }
}
