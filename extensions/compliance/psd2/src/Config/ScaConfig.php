<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * SCA (Strong Customer Authentication) configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ScaConfig
{
    public function __construct(
        public int $challengeTimeoutSeconds = 300,
        public string $challengeStore = 'memory',
        public int $codeLength = 8,
    ) {}

    /**
     * @param array{
     *     challenge_timeout_seconds?: int,
     *     challenge_store?: string,
     *     code_length?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            challengeTimeoutSeconds: $data['challenge_timeout_seconds'] ?? 300,
            challengeStore: $data['challenge_store'] ?? 'memory',
            codeLength: $data['code_length'] ?? 8,
        );
    }
}
