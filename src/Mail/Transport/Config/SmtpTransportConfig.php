<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;
use SensitiveParameter;

/**
 * Configuration for the SMTP mail transport.
 */
#[Internal]
final readonly class SmtpTransportConfig
{
    public function __construct(
        public string $host = 'localhost',
        public int $port = 587,
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
        public string $encryption = 'tls',
        public int $timeout = 30,
        public ?string $ehloHostname = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: Coerce::string($data['host'] ?? null, 'localhost'),
            port: Coerce::int($data['port'] ?? null, 587),
            username: Coerce::nullableString($data['username'] ?? null),
            password: Coerce::nullableString($data['password'] ?? null),
            encryption: Coerce::string($data['encryption'] ?? null, 'tls'),
            timeout: Coerce::int($data['timeout'] ?? null, 30),
            ehloHostname: Coerce::nullableString($data['ehlo_hostname'] ?? null),
        );
    }
}
