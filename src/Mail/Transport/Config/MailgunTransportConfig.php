<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;
use SensitiveParameter;

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
            domain: Coerce::string($data['domain'] ?? null),
            apiKey: Coerce::string($data['api_key'] ?? null),
            endpoint: Coerce::string($data['endpoint'] ?? null, 'https://api.mailgun.net'),
        );
    }
}
