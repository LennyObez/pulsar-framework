<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;
use SensitiveParameter;

/**
 * Configuration for the Postmark mail transport.
 */
#[Internal]
final readonly class PostmarkTransportConfig
{
    public function __construct(
        #[SensitiveParameter]
        public string $serverToken = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            serverToken: Coerce::string($data['server_token'] ?? null),
        );
    }
}
