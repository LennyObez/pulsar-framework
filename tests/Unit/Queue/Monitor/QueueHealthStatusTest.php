<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Monitor\QueueHealthStatus;

#[CoversClass(QueueHealthStatus::class)]
final class QueueHealthStatusTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('healthy', QueueHealthStatus::Healthy->value);
        self::assertSame('degraded', QueueHealthStatus::Degraded->value);
        self::assertSame('unhealthy', QueueHealthStatus::Unhealthy->value);
    }
}
