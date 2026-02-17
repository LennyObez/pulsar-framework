<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\WebSocket\TypingIndicator;
use Pulsar\WebSocket\BroadcastManagerInterface;

#[CoversClass(TypingIndicator::class)]
final class TypingIndicatorTest extends TestCase
{
    public function testStartTypingBroadcastsToChannel(): void
    {
        $broadcast = $this->createMock(BroadcastManagerInterface::class);
        $broadcast->expects(self::once())
            ->method('broadcastExcept')
            ->with(
                'private-conversation.conv-1',
                'typing.start',
                [
                    'conversation_id' => 'conv-1',
                    'user_id' => 'user-1',
                ],
                ['conn-1'],
            );

        $indicator = new TypingIndicator($broadcast);
        $indicator->startTyping('conv-1', 'user-1', 'conn-1');
    }

    public function testStopTypingBroadcastsToChannel(): void
    {
        $broadcast = $this->createMock(BroadcastManagerInterface::class);
        $broadcast->expects(self::once())
            ->method('broadcastExcept')
            ->with(
                'private-conversation.conv-1',
                'typing.stop',
                [
                    'conversation_id' => 'conv-1',
                    'user_id' => 'user-1',
                ],
                ['conn-1'],
            );

        $indicator = new TypingIndicator($broadcast);
        $indicator->stopTyping('conv-1', 'user-1', 'conn-1');
    }
}
