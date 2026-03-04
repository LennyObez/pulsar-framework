<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\Message;
use Pulsar\Extension\Messaging\Domain\MessageType;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    public function testConstructSetsAllProperties(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-21T12:00:00+00:00');

        $message = new Message(
            id: 'msg-1',
            conversationId: 'conv-1',
            senderId: 'user-a',
            encryptedContent: 'base64ciphertext==',
            nonce: 'base64nonce==',
            type: MessageType::Text,
            timestamp: $timestamp,
        );

        self::assertSame('msg-1', $message->id);
        self::assertSame('conv-1', $message->conversationId);
        self::assertSame('user-a', $message->senderId);
        self::assertSame('base64ciphertext==', $message->encryptedContent);
        self::assertSame('base64nonce==', $message->nonce);
        self::assertSame(MessageType::Text, $message->type);
        self::assertSame($timestamp, $message->timestamp);
    }

    public function testImageMessage(): void
    {
        $message = new Message(
            id: 'msg-2',
            conversationId: 'conv-1',
            senderId: 'user-b',
            encryptedContent: 'encrypted-image-data',
            nonce: 'nonce-data',
            type: MessageType::Image,
            timestamp: new DateTimeImmutable(),
        );

        self::assertSame(MessageType::Image, $message->type);
    }
}
