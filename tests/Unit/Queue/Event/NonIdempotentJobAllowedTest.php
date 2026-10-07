<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Event\NonIdempotentJobAllowed;

#[CoversClass(NonIdempotentJobAllowed::class)]
final class NonIdempotentJobAllowedTest extends TestCase
{
    #[Test]
    public function holdsAllProperties(): void
    {
        $before = time();

        $event = new NonIdempotentJobAllowed(
            jobClass: 'App\\Jobs\\SendWireTransfer',
            reason: 'Approved by compliance team for regulated banking flow',
            reviewer: 'compliance-officer@bank.example',
        );

        $after = time();

        self::assertSame('App\\Jobs\\SendWireTransfer', $event->jobClass);
        self::assertSame('Approved by compliance team for regulated banking flow', $event->reason);
        self::assertSame('compliance-officer@bank.example', $event->reviewer);
        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }
}
