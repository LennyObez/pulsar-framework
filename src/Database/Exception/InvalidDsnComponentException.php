<?php

declare(strict_types=1);

namespace Pulsar\Database\Exception;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_map;
use function implode;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;

/**
 * Thrown when a DSN component (host, database name, charset, …) contains a
 * byte that would let an attacker punch out of its slot in the resulting
 * PDO DSN string.
 *
 * The PDO DSN format uses `;`, `=`, and NUL as structural delimiters. A
 * component sourced from an environment variable that contains any of
 * those bytes can override later parameters (`dbname=`, `unix_socket=`,
 * `charset=`) — the canonical "DSN injection" attack against frameworks
 * that build DSN strings via `sprintf()`.
 * @api
 */
#[Api(since: '1.0.0')]
final class InvalidDsnComponentException extends InvalidArgumentException
{
    public static function forbiddenByte(string $component, string $value): self
    {
        $sanitised = self::redact($value);

        return new self(sprintf(
            'Database connection component "%s" contains a forbidden byte (`;`, `=`, NUL, CR, or LF). '
            . 'These characters are interpreted as DSN delimiters by PDO and would let an environment '
            . 'variable override other connection parameters. Component (redacted): "%s".',
            $component,
            $sanitised,
        ));
    }

    /**
     * @param list<string> $allowed
     */
    public static function unknownSslMode(string $value, array $allowed): self
    {
        return new self(sprintf(
            'Database connection option "sslmode" has the unrecognised value "%s". '
            . 'libpq accepts only: %s. An unrecognised value is refused rather than passed '
            . 'through, because a typo would otherwise become a silently unencrypted connection.',
            self::redact($value),
            implode(', ', $allowed),
        ));
    }

    public static function sslModeNotADsnParameter(string $driver): self
    {
        return new self(sprintf(
            'Database connection for driver "%s" sets "sslmode"/"ssl_mode", which only PostgreSQL '
            . 'reads from its DSN. PDO discards string-keyed options, so this setting would have no '
            . 'effect and the connection would be unencrypted while appearing configured for TLS. '
            . 'Use the PDO SSL attributes (Pdo\Mysql::ATTR_SSL_CA and friends) instead.',
            $driver,
        ));
    }

    /**
     * @param list<string> $keys
     */
    public static function discardedOptionKeys(string $connection, array $keys): self
    {
        return new self(sprintf(
            'Database connection "%s" sets the option key(s) %s. PDO indexes its driver options by '
            . 'integer attribute constants and silently drops string keys, so these would never reach '
            . 'the driver. Remove them, or express them as the PDO::ATTR_* constant they were meant to be.',
            $connection,
            implode(', ', array_map(static fn(string $key): string => sprintf('"%s"', $key), $keys)),
        ));
    }

    private static function redact(string $value): string
    {
        $sanitised = (string) preg_replace('/[\x00-\x1F\x7F;=]/', '?', $value);

        return strlen($sanitised) > 64 ? substr($sanitised, 0, 64) . '…' : $sanitised;
    }
}
