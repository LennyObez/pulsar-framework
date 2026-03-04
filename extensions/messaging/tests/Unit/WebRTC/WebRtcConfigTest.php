<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\WebRTC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\WebRTC\WebRtcConfig;

#[CoversClass(WebRtcConfig::class)]
final class WebRtcConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new WebRtcConfig();

        self::assertCount(1, $config->iceServers);
        self::assertSame('stun:stun.l.google.com:19302', $config->iceServers[0]['urls']);
        self::assertSame(30, $config->callTimeoutSeconds);
        self::assertSame(0, $config->maxCallDurationSeconds);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = WebRtcConfig::fromArray([
            'ice_servers' => [
                ['urls' => 'stun:stun.example.com:3478'],
                ['urls' => 'turn:turn.example.com:3478', 'username' => 'user', 'credential' => 'pass'],
            ],
            'call_timeout' => 60,
            'max_call_duration' => 3600,
        ]);

        self::assertCount(2, $config->iceServers);
        self::assertSame('stun:stun.example.com:3478', $config->iceServers[0]['urls']);
        self::assertSame('turn:turn.example.com:3478', $config->iceServers[1]['urls']);
        self::assertSame('user', $config->iceServers[1]['username']);
        self::assertSame('pass', $config->iceServers[1]['credential']);
        self::assertSame(60, $config->callTimeoutSeconds);
        self::assertSame(3600, $config->maxCallDurationSeconds);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $config = WebRtcConfig::fromArray([]);

        self::assertCount(1, $config->iceServers);
        self::assertSame(30, $config->callTimeoutSeconds);
    }

    public function testFromArraySkipsInvalidIceServers(): void
    {
        $config = WebRtcConfig::fromArray([
            'ice_servers' => [
                ['urls' => 'stun:valid.example.com'],
                ['invalid' => 'no-urls-field'],
            ],
        ]);

        self::assertCount(1, $config->iceServers);
    }
}
