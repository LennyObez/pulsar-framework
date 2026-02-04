<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_string;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;

/**
 * Typed configuration DTO for a single database connection.
 */
#[Api(since: '1.0.0')]
readonly class ConnectionConfig
{
    public function __construct(
        public string $name,
        public Driver $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $charset,
        public string $collation,
        /** @var array<string, mixed> */
        public array $options,
    ) {}

    /**
     * Build from a raw config array and environment.
     *
     * @param array<string, mixed> $data Raw connection config array
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data, Environment $environment): self
    {
        $rawDriver = $data['driver'] ?? 'mysql';
        $driverString = is_string($rawDriver) ? $rawDriver : 'mysql';
        $driver = Driver::from($driverString);

        /** @var string $hostDefault */
        $hostDefault = $data['host'] ?? '127.0.0.1';
        $host = $environment->get('DB_HOST') ?? $hostDefault;

        $portEnv = $environment->get('DB_PORT');
        /** @var int|string $portDefault */
        $portDefault = $data['port'] ?? $driver->defaultPort();
        $port = $portEnv !== null ? (int) $portEnv : (int) $portDefault;

        /** @var string $dbDefault */
        $dbDefault = $data['database'] ?? '';
        $database = $environment->get('DB_DATABASE') ?? $dbDefault;

        /** @var string $usernameDefault */
        $usernameDefault = $data['username'] ?? '';
        $username = $environment->get('DB_USERNAME') ?? $usernameDefault;

        /** @var string $passwordDefault */
        $passwordDefault = $data['password'] ?? '';
        $password = $environment->get('DB_PASSWORD') ?? $passwordDefault;

        /** @var string $charset */
        $charset = $data['charset'] ?? 'utf8mb4';

        /** @var string $collation */
        $collation = $data['collation'] ?? 'utf8mb4_unicode_ci';

        /** @var array<string, mixed> $options */
        $options = $data['options'] ?? [];

        return new self(
            name: $name,
            driver: $driver,
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            password: $password,
            charset: $charset,
            collation: $collation,
            options: $options,
        );
    }
}
