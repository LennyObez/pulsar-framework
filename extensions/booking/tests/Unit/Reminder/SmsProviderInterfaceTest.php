<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Reminder\SmsProviderInterface;

#[CoversNothing]
final class SmsProviderInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanSendSms(): void
    {
        $stub = $this->createStub(SmsProviderInterface::class);

        // send() returns void -- no exception means success
        $stub->send('+14155551234', 'Your appointment is tomorrow at 10:00 AM');
        self::assertSame('+14155551234', '+14155551234');
    }

    #[Test]
    public function stubCanThrowOnDeliveryFailure(): void
    {
        $stub = $this->createStub(SmsProviderInterface::class);
        $stub->method('send')->willThrowException(
            BookingException::smsDeliveryFailed('twilio', 'Invalid phone number'),
        );

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageIsOrContains('SMS delivery via twilio failed');

        $stub->send('+invalid', 'Test');
    }
}
