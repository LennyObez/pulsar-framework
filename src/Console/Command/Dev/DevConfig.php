<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Dev;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for the Docker development environment.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DevConfig
{
    /**
     * @param string $phpVersion PHP version for the container
     * @param string $database Database driver (pgsql, mysql, sqlite)
     * @param bool $redis Whether to include a Redis service
     * @param bool $mailpit Whether to include Mailpit for email testing
     * @param string $serverDriver HTTP server driver (frankenphp, built-in)
     * @param int $appPort Host port for the application
     * @param int $dbPort Host port for the database
     * @param int $redisPort Host port for Redis
     * @param int $mailpitSmtpPort Host port for Mailpit SMTP
     * @param int $mailpitWebPort Host port for Mailpit web UI
     * @param int $memoryLimit PHP memory limit in MB
     * @param bool $xdebug Whether to enable Xdebug
     * @param list<string> $phpExtensions Additional PHP extensions
     * @param string $projectName Docker Compose project name
     */
    public function __construct(
        public string $phpVersion = '8.5',
        public string $database = 'pgsql',
        public bool $redis = true,
        public bool $mailpit = true,
        public string $serverDriver = 'built-in',
        public int $appPort = 8080,
        public int $dbPort = 5432,
        public int $redisPort = 6379,
        public int $mailpitSmtpPort = 1025,
        public int $mailpitWebPort = 8025,
        public int $memoryLimit = 512,
        public bool $xdebug = true,
        public array $phpExtensions = [],
        public string $projectName = 'pulsar',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $extensions */
        $extensions = isset($data['php_extensions']) && is_array($data['php_extensions'])
            ? array_values(array_filter($data['php_extensions'], 'is_string'))
            : [];

        return new self(
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : '8.5',
            database: self::validDatabase($data['database'] ?? null),
            redis: is_bool($data['redis'] ?? null) ? $data['redis'] : true,
            mailpit: is_bool($data['mailpit'] ?? null) ? $data['mailpit'] : true,
            serverDriver: self::validServer($data['server_driver'] ?? null),
            appPort: self::validPort($data['app_port'] ?? null, 8080),
            dbPort: self::validPort($data['db_port'] ?? null, 5432),
            redisPort: self::validPort($data['redis_port'] ?? null, 6379),
            mailpitSmtpPort: self::validPort($data['mailpit_smtp_port'] ?? null, 1025),
            mailpitWebPort: self::validPort($data['mailpit_web_port'] ?? null, 8025),
            memoryLimit: is_int($data['memory_limit'] ?? null) ? $data['memory_limit'] : 512,
            xdebug: is_bool($data['xdebug'] ?? null) ? $data['xdebug'] : true,
            phpExtensions: $extensions,
            projectName: is_string($data['project_name'] ?? null) ? $data['project_name'] : 'pulsar',
        );
    }

    /**
     * Get the default database port for the configured driver.
     */
    public function defaultDbPort(): int
    {
        return match ($this->database) {
            'mysql' => 3306,
            'pgsql' => 5432,
            default => 0,
        };
    }

    /**
     * Whether the database requires a separate container (not SQLite).
     */
    public function requiresDatabaseContainer(): bool
    {
        return $this->database !== 'sqlite';
    }

    private static function validDatabase(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['pgsql', 'mysql', 'sqlite'], true)) {
            return $value;
        }

        return 'pgsql';
    }

    private static function validServer(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['frankenphp', 'built-in'], true)) {
            return $value;
        }

        return 'built-in';
    }

    private static function validPort(mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 1 && $value <= 65535) {
            return $value;
        }

        return $default;
    }
}
