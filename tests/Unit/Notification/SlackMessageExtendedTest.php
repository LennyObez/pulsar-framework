<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\SlackMessage;

#[CoversClass(SlackMessage::class)]
final class SlackMessageExtendedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Hello']]];
        $message = new SlackMessage(
            channel: '#alerts',
            text: 'Alert: server down',
            blocks: $blocks,
            username: 'PulsarBot',
            iconEmoji: ':robot_face:',
        );

        self::assertSame('#alerts', $message->channel);
        self::assertSame('Alert: server down', $message->text);
        self::assertSame($blocks, $message->blocks);
        self::assertSame('PulsarBot', $message->username);
        self::assertSame(':robot_face:', $message->iconEmoji);
    }

    #[Test]
    public function defaultBlocksIsEmptyArray(): void
    {
        $message = new SlackMessage(channel: '#general', text: 'Hi');

        self::assertSame([], $message->blocks);
    }

    #[Test]
    public function defaultUsernameIsNull(): void
    {
        $message = new SlackMessage(channel: '#general', text: 'Hi');

        self::assertNull($message->username);
    }

    #[Test]
    public function defaultIconEmojiIsNull(): void
    {
        $message = new SlackMessage(channel: '#general', text: 'Hi');

        self::assertNull($message->iconEmoji);
    }

    #[Test]
    public function supportsUserMentionChannel(): void
    {
        $message = new SlackMessage(channel: '@john', text: 'DM');

        self::assertSame('@john', $message->channel);
    }
}
