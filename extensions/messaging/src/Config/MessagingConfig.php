<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\WebRTC\WebRtcConfig;

use function is_array;
use function is_bool;
use function is_int;

/**
 * Configuration DTO for the Messaging extension.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $webRtcData = is_array($data['webrtc'] ?? null) ? $data['webrtc'] : [];

        return new self(
            e2eeEnabled: is_bool($data['e2ee_enabled'] ?? null) ? $data['e2ee_enabled'] : true,
            maxConversationParticipants: is_int($data['max_conversation_participants'] ?? null) ? $data['max_conversation_participants'] : 100,
            maxMessageSizeBytes: is_int($data['max_message_size_bytes'] ?? null) ? $data['max_message_size_bytes'] : 65_536,
            messageRetentionDays: is_int($data['message_retention_days'] ?? null) ? $data['message_retention_days'] : 0,
            shamirThreshold: is_int($data['shamir_threshold'] ?? null) ? $data['shamir_threshold'] : 3,
            shamirTotalShares: is_int($data['shamir_total_shares'] ?? null) ? $data['shamir_total_shares'] : 5,
            webRtc: WebRtcConfig::fromArray($webRtcData),
        );
    }
}
