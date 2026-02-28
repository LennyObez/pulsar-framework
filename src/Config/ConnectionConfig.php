<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;

use function is_string;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

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
     * @param string|null $basePath Project root for resolving relative SQLite paths.
     *                              When null, relative paths are left as-is (resolved by Driver at DSN time).
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data, Environment $environment, ?string $basePath = null): self
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

        // For SQLite, resolve relative paths against the project root at config time.
        // This ensures symlinked projects write to their own database, not the framework's.
        if ($driver === Driver::SQLite && $basePath !== null) {
            $database = self::resolveSqlitePath($database, $basePath);
        }

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

    /**
     * Resolve a relative SQLite database path against a project root.
     *
     * Absolute paths, :memory:, and empty strings are returned unchanged.
     */
    private static function resolveSqlitePath(string $database, string $basePath): string
    {
        if ($database === ':memory:' || $database === '') {
            return $database;
        }

        // Already absolute (Unix or Windows)
        if (str_starts_with($database, '/') || (PHP_OS_FAMILY === 'Windows' && isset($database[1]) && $database[1] === ':')) {
            return $database;
        }

        return $basePath . DIRECTORY_SEPARATOR . $database;
    }
}
