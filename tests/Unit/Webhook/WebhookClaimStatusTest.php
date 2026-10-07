<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\WebhookClaimStatus;

#[CoversNothing]
final class WebhookClaimStatusTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = WebhookClaimStatus::cases();

        self::assertCount(2, $cases);
        self::assertContains(WebhookClaimStatus::Replay, $cases);
        self::assertContains(WebhookClaimStatus::Claimed, $cases);
    }
}
