<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

use function is_int;
use function is_string;

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
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: is_string($data['host'] ?? null) ? $data['host'] : 'localhost',
            port: is_int($data['port'] ?? null) ? $data['port'] : 587,
            username: is_string($data['username'] ?? null) ? $data['username'] : null,
            password: is_string($data['password'] ?? null) ? $data['password'] : null,
            encryption: is_string($data['encryption'] ?? null) ? $data['encryption'] : 'tls',
            timeout: is_int($data['timeout'] ?? null) ? $data['timeout'] : 30,
        );
    }
}
