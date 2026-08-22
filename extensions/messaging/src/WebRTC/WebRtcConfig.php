<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebRTC;

use Pulsar\Api\Api;

use function is_string;

/**
 * Configuration for WebRTC STUN/TURN servers and ICE behavior.
 * @api
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
     * @param array{
     *     ice_servers?: list<array{urls?: string, username?: string, credential?: string}>,
     *     call_timeout?: int,
     *     max_call_duration?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $iceServers = [['urls' => 'stun:stun.l.google.com:19302']];
        if (isset($data['ice_servers'])) {
            $iceServers = [];
            foreach ($data['ice_servers'] as $server) {
                $urls = $server['urls'] ?? null;
                if (!is_string($urls)) {
                    continue;
                }

                $entry = ['urls' => $urls];

                if (isset($server['username'])) {
                    $entry['username'] = $server['username'];
                }

                if (isset($server['credential'])) {
                    $entry['credential'] = $server['credential'];
                }

                $iceServers[] = $entry;
            }
        }

        return new self(
            iceServers: $iceServers,
            callTimeoutSeconds: $data['call_timeout'] ?? 30,
            maxCallDurationSeconds: $data['max_call_duration'] ?? 0,
        );
    }
}
