<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Envelope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;

#[CoversClass(BackoffStrategy::class)]
final class BackoffStrategyTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('fixed', BackoffStrategy::Fixed->value);
        self::assertSame('exponential', BackoffStrategy::Exponential->value);
        self::assertSame('custom', BackoffStrategy::Custom->value);
    }
}
