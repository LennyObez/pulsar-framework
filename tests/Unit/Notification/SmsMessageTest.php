<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\SmsMessage;

#[CoversClass(SmsMessage::class)]
final class SmsMessageTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $message = new SmsMessage(
            to: '+1234567890',
            body: 'Your code is 12345',
            from: '+0987654321',
        );

        self::assertSame('+1234567890', $message->to);
        self::assertSame('Your code is 12345', $message->body);
        self::assertSame('+0987654321', $message->from);
    }

    #[Test]
    public function defaultFromIsNull(): void
    {
        $message = new SmsMessage(to: '+1111', body: 'Hello');

        self::assertNull($message->from);
    }
}
