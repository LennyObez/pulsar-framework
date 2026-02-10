<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\WebhookProcessingStatus;

#[CoversClass(WebhookProcessingStatus::class)]
final class WebhookProcessingStatusTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = WebhookProcessingStatus::cases();

        self::assertCount(4, $cases);
        self::assertContains(WebhookProcessingStatus::Processed, $cases);
        self::assertContains(WebhookProcessingStatus::Replay, $cases);
        self::assertContains(WebhookProcessingStatus::InvalidSignature, $cases);
        self::assertContains(WebhookProcessingStatus::HandlerError, $cases);
    }
}
