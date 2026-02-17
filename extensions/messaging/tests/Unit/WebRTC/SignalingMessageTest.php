<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\WebRTC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\WebRTC\SignalingMessage;

#[CoversClass(SignalingMessage::class)]
final class SignalingMessageTest extends TestCase
{
    public function testFromArray(): void
    {
        $msg = SignalingMessage::fromArray([
            'type' => 'offer',
            'from_user_id' => 'alice',
            'to_user_id' => 'bob',
            'call_id' => 'call-123',
            'payload' => ['sdp' => 'v=0...'],
        ]);

        self::assertSame('offer', $msg->type);
        self::assertSame('alice', $msg->fromUserId);
        self::assertSame('bob', $msg->toUserId);
        self::assertSame('call-123', $msg->callId);
        self::assertSame(['sdp' => 'v=0...'], $msg->payload);
    }

    public function testFromArrayWithDefaults(): void
    {
        $msg = SignalingMessage::fromArray([]);

        self::assertSame('', $msg->type);
        self::assertSame('', $msg->fromUserId);
        self::assertSame('', $msg->toUserId);
        self::assertSame('', $msg->callId);
        self::assertSame([], $msg->payload);
    }

    public function testToArray(): void
    {
        $msg = new SignalingMessage(
            type: 'answer',
            fromUserId: 'bob',
            toUserId: 'alice',
            callId: 'call-123',
            payload: ['sdp' => 'v=0...', 'type' => 'answer'],
        );

        $array = $msg->toArray();

        self::assertSame('answer', $array['type']);
        self::assertSame('bob', $array['from_user_id']);
        self::assertSame('alice', $array['to_user_id']);
        self::assertSame('call-123', $array['call_id']);
        self::assertSame(['sdp' => 'v=0...', 'type' => 'answer'], $array['payload']);
    }

    public function testTypePredicates(): void
    {
        $offer = new SignalingMessage('offer', 'a', 'b', 'c', []);
        self::assertTrue($offer->isOffer());
        self::assertFalse($offer->isAnswer());
        self::assertFalse($offer->isIceCandidate());
        self::assertFalse($offer->isBye());

        $answer = new SignalingMessage('answer', 'a', 'b', 'c', []);
        self::assertTrue($answer->isAnswer());

        $ice = new SignalingMessage('ice-candidate', 'a', 'b', 'c', []);
        self::assertTrue($ice->isIceCandidate());

        $bye = new SignalingMessage('bye', 'a', 'b', 'c', []);
        self::assertTrue($bye->isBye());
    }
}
