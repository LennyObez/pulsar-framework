<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook\Verifier;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\Verifier\PostmarkWebhookVerifier;
use Pulsar\Mail\Webhook\WebhookRequest;

use function time;

#[CoversClass(PostmarkWebhookVerifier::class)]
final class PostmarkWebhookVerifierTest extends TestCase
{
    private const string WEBHOOK_TOKEN = 'postmark-webhook-token-abc';

    #[Test]
    public function verifiesValidToken(): void
    {
        $verifier = new PostmarkWebhookVerifier(self::WEBHOOK_TOKEN);

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            ['x-postmark-token' => self::WEBHOOK_TOKEN],
            '10.0.0.1',
            time(),
            'postmark',
        );

        self::assertTrue($verifier->verify($request));
    }

    #[Test]
    public function rejectsInvalidToken(): void
    {
        $verifier = new PostmarkWebhookVerifier(self::WEBHOOK_TOKEN);

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            ['x-postmark-token' => 'wrong-token'],
            '10.0.0.1',
            time(),
            'postmark',
        );

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingTokenHeader(): void
    {
        $verifier = new PostmarkWebhookVerifier(self::WEBHOOK_TOKEN);

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            [],
            '10.0.0.1',
            time(),
            'postmark',
        );

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsDifferentHeaderName(): void
    {
        $verifier = new PostmarkWebhookVerifier(self::WEBHOOK_TOKEN);

        $request = new WebhookRequest(
            '{"event":"delivered"}',
            ['authorization' => self::WEBHOOK_TOKEN],
            '10.0.0.1',
            time(),
            'postmark',
        );

        self::assertFalse($verifier->verify($request));
    }
}
