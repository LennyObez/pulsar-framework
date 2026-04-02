<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
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
     * @param array{
     *     host?: string,
     *     port?: int,
     *     username?: string|null,
     *     password?: string|null,
     *     encryption?: string,
     *     timeout?: int,
     *     ehlo_hostname?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: $data['host'] ?? 'localhost',
            port: $data['port'] ?? 587,
            username: $data['username'] ?? null,
            password: $data['password'] ?? null,
            encryption: $data['encryption'] ?? 'tls',
            timeout: $data['timeout'] ?? 30,
            ehloHostname: $data['ehlo_hostname'] ?? null,
        );
    }
}
