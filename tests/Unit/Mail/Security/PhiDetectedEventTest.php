<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Security\PhiDetectedEvent;

#[CoversClass(PhiDetectedEvent::class)]
final class PhiDetectedEventTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $event = new PhiDetectedEvent(
            messageId: 'msg-phi-11111111-2222-3333-4444-555555555555',
            field: 'subject',
            pattern: 'ssn',
            detectedAt: 1709827200,
        );

        self::assertSame('msg-phi-11111111-2222-3333-4444-555555555555', $event->messageId);
        self::assertSame('subject', $event->field);
        self::assertSame('ssn', $event->pattern);
        self::assertSame(1709827200, $event->detectedAt);
    }

    #[Test]
    public function createFactorySetsTimestamp(): void
    {
        $before = time();
        $event = PhiDetectedEvent::create(
            messageId: 'msg-phi-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            field: 'body',
            pattern: 'medical_record_number',
        );
        $after = time();

        self::assertSame('msg-phi-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $event->messageId);
        self::assertSame('body', $event->field);
        self::assertSame('medical_record_number', $event->pattern);
        self::assertGreaterThanOrEqual($before, $event->detectedAt);
        self::assertLessThanOrEqual($after, $event->detectedAt);
    }
}
