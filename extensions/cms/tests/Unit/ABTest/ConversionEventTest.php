<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ABTest;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\ConversionEvent;

#[CoversClass(ConversionEvent::class)]
final class ConversionEventTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-28T12:00:00Z');

        $event = new ConversionEvent(
            id: 'evt-001',
            experimentId: 'exp-001',
            variantId: 'var-001',
            visitorId: 'vis-abc',
            type: 'click',
            createdAt: $now,
        );

        self::assertSame('evt-001', $event->id);
        self::assertSame('exp-001', $event->experimentId);
        self::assertSame('var-001', $event->variantId);
        self::assertSame('vis-abc', $event->visitorId);
        self::assertSame('click', $event->type);
        self::assertSame($now, $event->createdAt);
    }
}
