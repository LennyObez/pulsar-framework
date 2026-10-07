<?php

declare(strict_types=1);

/**
 * Messaging extension configuration.
 *
 * @see \Pulsar\Extension\Messaging\Config\MessagingConfig
 */
return [
    // Whether E2EE is mandatory for private conversations (recommended: true)
    'e2ee_enabled' => true,

    // Maximum participants in a group conversation
    'max_conversation_participants' => 100,

    // Maximum encrypted message size in bytes (64 KB default)
    'max_message_size_bytes' => 65_536,

    // Days to retain messages (0 = keep forever)
    'message_retention_days' => 0,

    // Shamir's Secret Sharing: minimum shares to recover
    'shamir_threshold' => 3,

    // Shamir's Secret Sharing: total shares to create
    'shamir_total_shares' => 5,

    // WebRTC configuration
    'webrtc' => [
        'ice_servers' => [
            ['urls' => 'stun:stun.l.google.com:19302'],
        ],

        // Seconds to wait for callee to answer before marking missed
        'call_timeout' => 30,

        // Maximum call duration in seconds (0 = unlimited)
        'max_call_duration' => 0,
    ],
];
