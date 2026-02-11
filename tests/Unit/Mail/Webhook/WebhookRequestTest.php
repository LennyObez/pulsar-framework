<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\WebhookRequest;

#[CoversClass(WebhookRequest::class)]
final class WebhookRequestTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $request = new WebhookRequest(
            payload: '{"event":"bounce","email":"patient@hospital.org"}',
            headers: [
                'Content-Type' => 'application/json',
                'X-Mailgun-Signature' => 'abc123def456',
            ],
            sourceIp: '198.51.100.42',
            timestamp: 1709827200,
            provider: 'mailgun',
        );

        self::assertSame('{"event":"bounce","email":"patient@hospital.org"}', $request->payload);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('198.51.100.42', $request->sourceIp);
        self::assertSame(1709827200, $request->timestamp);
        self::assertSame('mailgun', $request->provider);
    }
}
