<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\SmsGatewayInterface;
use Pulsar\Notification\SmsMessage;

#[CoversClass(SmsGatewayInterface::class)]
final class SmsGatewayInterfaceTest extends TestCase
{
    #[Test]
    public function implementationReceivesSmsMessage(): void
    {
        $gateway = new class implements SmsGatewayInterface {
            /** @var list<SmsMessage> */
            public array $sentMessages = [];

            public function send(SmsMessage $message): void
            {
                $this->sentMessages[] = $message;
            }
        };

        $message = new SmsMessage('+15551234567', 'Verification code: 123456');
        $gateway->send($message);

        self::assertCount(1, $gateway->sentMessages);
        self::assertSame('+15551234567', $gateway->sentMessages[0]->to);
        self::assertSame('Verification code: 123456', $gateway->sentMessages[0]->body);
    }

    #[Test]
    public function implementationCanBeCalledMultipleTimes(): void
    {
        $gateway = new class implements SmsGatewayInterface {
            public int $count = 0;

            public function send(SmsMessage $message): void
            {
                $this->count++;
            }
        };

        $message = new SmsMessage('+15551234567', 'Test');
        $gateway->send($message);
        $gateway->send($message);
        $gateway->send($message);

        self::assertSame(3, $gateway->count);
    }
}
