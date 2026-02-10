<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

use function is_string;

/**
 * Configuration for the Mailgun mail transport.
 */
#[Internal]
final readonly class MailgunTransportConfig
{
    public function __construct(
        public string $domain = '',
        #[SensitiveParameter]
        public string $apiKey = '',
        public string $endpoint = 'https://api.mailgun.net',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            domain: is_string($data['domain'] ?? null) ? $data['domain'] : '',
            apiKey: is_string($data['api_key'] ?? null) ? $data['api_key'] : '',
            endpoint: is_string($data['endpoint'] ?? null) ? $data['endpoint'] : 'https://api.mailgun.net',
        );
    }
}
