<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\ChatRole;

#[CoversClass(ChatMessage::class)]
final class ChatMessageTest extends TestCase
{
    #[Test]
    public function systemFactoryCreatesSystemMessage(): void
    {
        $msg = ChatMessage::system('You are helpful.');

        self::assertSame(ChatRole::System, $msg->role);
        self::assertSame('You are helpful.', $msg->content);
        self::assertNull($msg->name);
        self::assertNull($msg->toolCallId);
    }

    #[Test]
    public function userFactoryCreatesUserMessage(): void
    {
        $msg = ChatMessage::user('Hello');

        self::assertSame(ChatRole::User, $msg->role);
        self::assertSame('Hello', $msg->content);
    }

    #[Test]
    public function assistantFactoryCreatesAssistantMessage(): void
    {
        $msg = ChatMessage::assistant('Hi there!');

        self::assertSame(ChatRole::Assistant, $msg->role);
        self::assertSame('Hi there!', $msg->content);
    }

    #[Test]
    public function toolResultFactoryCreatesToolMessage(): void
    {
        $msg = ChatMessage::toolResult('call_123', '{"temp": 22}');

        self::assertSame(ChatRole::Tool, $msg->role);
        self::assertSame('{"temp": 22}', $msg->content);
        self::assertSame('call_123', $msg->toolCallId);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $msg = new ChatMessage(
            role: ChatRole::User,
            content: 'test',
            name: 'Alice',
            toolCallId: 'tc_1',
        );

        self::assertSame('Alice', $msg->name);
        self::assertSame('tc_1', $msg->toolCallId);
    }

    #[Test]
    public function chatRoleEnumHasCorrectValues(): void
    {
        self::assertSame('system', ChatRole::System->value);
        self::assertSame('user', ChatRole::User->value);
        self::assertSame('assistant', ChatRole::Assistant->value);
        self::assertSame('tool', ChatRole::Tool->value);
    }
}
