<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\NotificationChannelType;

#[CoversClass(NotificationChannelType::class)]
final class NotificationChannelTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('channelProvider')]
    public function backingValuesAreCorrect(NotificationChannelType $channel, string $expected): void
    {
        self::assertSame($expected, $channel->value);
    }

    /**
     * @return iterable<string, array{NotificationChannelType, string}>
     */
    public static function channelProvider(): iterable
    {
        yield 'mail' => [NotificationChannelType::Mail, 'mail'];
        yield 'sms' => [NotificationChannelType::Sms, 'sms'];
        yield 'database' => [NotificationChannelType::Database, 'database'];
        yield 'slack' => [NotificationChannelType::Slack, 'slack'];
        yield 'webhook' => [NotificationChannelType::Webhook, 'webhook'];
        yield 'log' => [NotificationChannelType::Log, 'log'];
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(8, NotificationChannelType::cases());
    }
}
