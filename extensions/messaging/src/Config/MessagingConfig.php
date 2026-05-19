<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\WebRTC\WebRtcConfig;

/**
 * Configuration DTO for the Messaging extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MessagingConfig
{
    /**
     * @param bool $e2eeEnabled Whether E2EE is mandatory for private channels (default: true)
     * @param int $maxConversationParticipants Maximum participants in a group conversation
     * @param int $maxMessageSizeBytes Maximum encrypted message size
     * @param int $messageRetentionDays Days to retain messages (0 = forever)
     * @param int $shamirThreshold Minimum shares to reconstruct recovery key
     * @param int $shamirTotalShares Total recovery key shares to create
     * @param WebRtcConfig $webRtc WebRTC configuration
     */
    public function __construct(
        public bool $e2eeEnabled = true,
        public int $maxConversationParticipants = 100,
        public int $maxMessageSizeBytes = 65_536,
        public int $messageRetentionDays = 0,
        public int $shamirThreshold = 3,
        public int $shamirTotalShares = 5,
        public WebRtcConfig $webRtc = new WebRtcConfig(),
    ) {}

    /**
     * @param array{
     *     e2ee_enabled?: bool,
     *     max_conversation_participants?: int,
     *     max_message_size_bytes?: int,
     *     message_retention_days?: int,
     *     shamir_threshold?: int,
     *     shamir_total_shares?: int,
     *     webrtc?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            e2eeEnabled: $data['e2ee_enabled'] ?? true,
            maxConversationParticipants: $data['max_conversation_participants'] ?? 100,
            maxMessageSizeBytes: $data['max_message_size_bytes'] ?? 65_536,
            messageRetentionDays: $data['message_retention_days'] ?? 0,
            shamirThreshold: $data['shamir_threshold'] ?? 3,
            shamirTotalShares: $data['shamir_total_shares'] ?? 5,
            webRtc: WebRtcConfig::fromArray($data['webrtc'] ?? []),
        );
    }
}
