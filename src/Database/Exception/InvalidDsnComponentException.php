<?php

declare(strict_types=1);

namespace Pulsar\Database\Exception;

use InvalidArgumentException;
use Pulsar\Api\Api;

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
 * that build DSN strings via `sprintf()` (F11.1).
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

    private static function redact(string $value): string
    {
        $sanitised = (string) preg_replace('/[\x00-\x1F\x7F;=]/', '?', $value);

        return strlen($sanitised) > 64 ? substr($sanitised, 0, 64) . '…' : $sanitised;
    }
}
