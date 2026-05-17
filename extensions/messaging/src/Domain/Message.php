<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * An encrypted message within a conversation.
 *
 * The server only stores ciphertext: it cannot read message content.
 * Decryption happens client-side using the shared conversation key.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Message
{
    /**
     * @param string $id Unique message identifier
     * @param string $conversationId Conversation this message belongs to
     * @param string $senderId User ID of the sender
     * @param string $encryptedContent XChaCha20-Poly1305 ciphertext (base64-encoded)
     * @param string $nonce Encryption nonce (base64-encoded, 24 bytes)
     * @param MessageType $type Type of message content
     * @param DateTimeImmutable $timestamp When the message was sent
     */
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderId,
        public string $encryptedContent,
        public string $nonce,
        public MessageType $type,
        public DateTimeImmutable $timestamp,
    ) {}
}
