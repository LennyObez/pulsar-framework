<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Config\MessagingConfig;

#[CoversClass(MessagingConfig::class)]
final class MessagingConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new MessagingConfig();

        self::assertTrue($config->e2eeEnabled);
        self::assertSame(100, $config->maxConversationParticipants);
        self::assertSame(65_536, $config->maxMessageSizeBytes);
        self::assertSame(0, $config->messageRetentionDays);
        self::assertSame(3, $config->shamirThreshold);
        self::assertSame(5, $config->shamirTotalShares);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = MessagingConfig::fromArray([
            'e2ee_enabled' => false,
            'max_conversation_participants' => 50,
            'max_message_size_bytes' => 1024,
            'message_retention_days' => 90,
            'shamir_threshold' => 2,
            'shamir_total_shares' => 3,
            'webrtc' => [
                'call_timeout' => 45,
            ],
        ]);

        self::assertFalse($config->e2eeEnabled);
        self::assertSame(50, $config->maxConversationParticipants);
        self::assertSame(1024, $config->maxMessageSizeBytes);
        self::assertSame(90, $config->messageRetentionDays);
        self::assertSame(2, $config->shamirThreshold);
        self::assertSame(3, $config->shamirTotalShares);
        self::assertSame(45, $config->webRtc->callTimeoutSeconds);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $config = MessagingConfig::fromArray([]);

        self::assertTrue($config->e2eeEnabled);
        self::assertSame(100, $config->maxConversationParticipants);
    }
}
