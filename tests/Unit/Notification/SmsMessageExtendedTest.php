<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\SmsMessage;

#[CoversClass(SmsMessage::class)]
final class SmsMessageExtendedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $message = new SmsMessage(
            to: '+14155551234',
            body: 'Your verification code is 1234',
            from: '+14155550000',
        );

        self::assertSame('+14155551234', $message->to);
        self::assertSame('Your verification code is 1234', $message->body);
        self::assertSame('+14155550000', $message->from);
    }

    #[Test]
    public function defaultFromIsNull(): void
    {
        $message = new SmsMessage(to: '+14155551234', body: 'Hello');

        self::assertNull($message->from);
    }

    #[Test]
    public function emptyBodyIsValid(): void
    {
        $message = new SmsMessage(to: '+14155551234', body: '');

        self::assertSame('', $message->body);
    }

    #[Test]
    public function longBodyIsPreserved(): void
    {
        $longBody = str_repeat('A', 1600);
        $message = new SmsMessage(to: '+14155551234', body: $longBody);

        self::assertSame(1600, mb_strlen($message->body));
    }
}
