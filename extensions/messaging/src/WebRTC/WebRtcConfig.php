<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebRTC;

use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

/**
 * Configuration for WebRTC STUN/TURN servers and ICE behavior.
 */
#[Api(since: '1.0.0')]
final readonly class WebRtcConfig
{
    /**
     * @param list<array{urls: string, username?: string, credential?: string}> $iceServers STUN/TURN servers
     * @param int $callTimeoutSeconds Max ringing time before marking as missed
     * @param int $maxCallDurationSeconds Maximum call duration (0 = unlimited)
     */
    public function __construct(
        public array $iceServers = [['urls' => 'stun:stun.l.google.com:19302']],
        public int $callTimeoutSeconds = 30,
        public int $maxCallDurationSeconds = 0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $iceServers = [['urls' => 'stun:stun.l.google.com:19302']];
        if (isset($data['ice_servers']) && is_array($data['ice_servers'])) {
            $iceServers = [];
            /** @var array<string, mixed> $server */
            foreach ($data['ice_servers'] as $server) {
                if (!is_string($server['urls'] ?? null)) {
                    continue;
                }

                $entry = ['urls' => $server['urls']];

                if (is_string($server['username'] ?? null)) {
                    $entry['username'] = $server['username'];
                }

                if (is_string($server['credential'] ?? null)) {
                    $entry['credential'] = $server['credential'];
                }

                $iceServers[] = $entry;
            }
        }

        return new self(
            iceServers: $iceServers,
            callTimeoutSeconds: is_int($data['call_timeout'] ?? null) ? $data['call_timeout'] : 30,
            maxCallDurationSeconds: is_int($data['max_call_duration'] ?? null) ? $data['max_call_duration'] : 0,
        );
    }
}
