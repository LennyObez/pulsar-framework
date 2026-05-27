<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            challengeTimeoutSeconds: Coerce::int($data['challenge_timeout_seconds'] ?? null, 300),
            challengeStore: Coerce::string($data['challenge_store'] ?? null, 'memory'),
            codeLength: Coerce::int($data['code_length'] ?? null, 8),
        );
    }
}
