<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
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
     * @param array{
     *     domain?: string,
     *     api_key?: string,
     *     endpoint?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            domain: $data['domain'] ?? '',
            apiKey: $data['api_key'] ?? '',
            endpoint: $data['endpoint'] ?? 'https://api.mailgun.net',
        );
    }
}
