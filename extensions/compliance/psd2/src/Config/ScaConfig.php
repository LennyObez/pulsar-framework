<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * SCA (Strong Customer Authentication) configuration.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            challengeTimeoutSeconds: is_int($data['challenge_timeout_seconds'] ?? null) ? $data['challenge_timeout_seconds'] : 300,
            challengeStore: is_string($data['challenge_store'] ?? null) ? $data['challenge_store'] : 'memory',
            codeLength: is_int($data['code_length'] ?? null) ? $data['code_length'] : 8,
        );
    }
}
