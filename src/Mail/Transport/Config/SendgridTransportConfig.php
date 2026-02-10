<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: is_string($data['api_key'] ?? null) ? $data['api_key'] : '',
        );
    }
}
