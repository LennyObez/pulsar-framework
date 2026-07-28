<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;

use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

/**
 * Typed configuration DTO for a single database connection.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConnectionConfig implements ReportsUnknownKeys
{
    /** Keys read from a single entry of `connections` in config/database.php. */
    private const array KNOWN_KEYS = [
        'driver', 'host', 'port', 'database', 'username', 'password',
        'charset', 'collation', 'options',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in this connection's raw array that
     *     the DTO does not read — a misspelled `database` silently connects to the
     *     empty default rather than the schema the operator named.
     */
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
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * Build from a raw config array and environment.
     *
     * @param array{
     *     driver?: string,
     *     host?: string,
     *     port?: int|string,
     *     database?: string,
     *     username?: string,
     *     password?: string,
     *     charset?: string,
     *     collation?: string,
     *     options?: array<string, mixed>,
     * } $data Raw connection config array
     * @param string|null $basePath Project root for resolving relative SQLite paths.
     *                              When null, relative paths are left as-is (resolved by Driver at DSN time).
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data, Environment $environment, ?string $basePath = null): self
    {
        $driver = Driver::from($data['driver'] ?? 'mysql');

        $host = $environment->get('DB_HOST') ?? $data['host'] ?? '127.0.0.1';

        $portEnv = $environment->get('DB_PORT');
        $port = $portEnv !== null ? (int) $portEnv : (int) ($data['port'] ?? $driver->defaultPort());

        $database = $environment->get('DB_DATABASE') ?? $data['database'] ?? '';

        // For SQLite, resolve relative paths against the project root at config time.
        // This ensures symlinked projects write to their own database, not the framework's.
        if ($driver === Driver::SQLite && $basePath !== null) {
            $database = self::resolveSqlitePath($database, $basePath);
        }

        return new self(
            name: $name,
            driver: $driver,
            host: $host,
            port: $port,
            database: $database,
            username: $environment->get('DB_USERNAME') ?? $data['username'] ?? '',
            password: $environment->get('DB_PASSWORD') ?? $data['password'] ?? '',
            charset: $data['charset'] ?? 'utf8mb4',
            collation: $data['collation'] ?? 'utf8mb4_unicode_ci',
            options: $data['options'] ?? [],
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
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
