<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
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
     * @param array{server_token?: string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            serverToken: $data['server_token'] ?? '',
        );
    }
}
