<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\SlackMessage;

#[CoversClass(SlackMessage::class)]
final class SlackMessageTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Hello']]];
        $message = new SlackMessage(
            channel: '#general',
            text: 'Fallback text',
            blocks: $blocks,
            username: 'PulsarBot',
            iconEmoji: ':robot_face:',
        );

        self::assertSame('#general', $message->channel);
        self::assertSame('Fallback text', $message->text);
        self::assertSame($blocks, $message->blocks);
        self::assertSame('PulsarBot', $message->username);
        self::assertSame(':robot_face:', $message->iconEmoji);
    }

    #[Test]
    public function defaultsForOptionalFields(): void
    {
        $message = new SlackMessage(channel: '#alerts', text: 'Alert!');

        self::assertSame([], $message->blocks);
        self::assertNull($message->username);
        self::assertNull($message->iconEmoji);
    }
}
