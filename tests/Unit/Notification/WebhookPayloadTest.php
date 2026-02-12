<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\WebhookPayload;

#[CoversClass(WebhookPayload::class)]
final class WebhookPayloadTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $payload = new WebhookPayload(
            url: 'https://example.com/hook',
            data: ['event' => 'user.created'],
            headers: ['X-Signature' => 'abc123'],
            method: 'PUT',
        );

        self::assertSame('https://example.com/hook', $payload->url);
        self::assertSame(['event' => 'user.created'], $payload->data);
        self::assertSame(['X-Signature' => 'abc123'], $payload->headers);
        self::assertSame('PUT', $payload->method);
    }

    #[Test]
    public function defaults(): void
    {
        $payload = new WebhookPayload(url: 'https://example.com');

        self::assertSame([], $payload->data);
        self::assertSame([], $payload->headers);
        self::assertSame('POST', $payload->method);
    }
}
