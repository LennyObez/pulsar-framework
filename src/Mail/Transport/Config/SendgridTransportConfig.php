<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

/**
 * Configuration for the Sendgrid mail transport.
 */
#[Internal]
final readonly class SendgridTransportConfig
{
    public function __construct(
        #[SensitiveParameter]
        public string $apiKey = '',
    ) {}

    /**
     * @param array{api_key?: string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: $data['api_key'] ?? '',
        );
    }
}
