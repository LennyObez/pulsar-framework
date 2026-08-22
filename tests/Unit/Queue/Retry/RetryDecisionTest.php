<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Retry;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Retry\RetryDecision;

#[CoversNothing]
final class RetryDecisionTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('retry', RetryDecision::Retry->value);
        self::assertSame('dead_letter', RetryDecision::DeadLetter->value);
        self::assertSame('discard', RetryDecision::Discard->value);
    }
}
