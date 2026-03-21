<?php

declare(strict_types=1);

namespace Pulsar\Database;

use NoDiscard;
use PDO;
use Pulsar\Api\Api;

/**
 * Typed parameter value object for PDO binding.
 *
 * Wraps raw values with PDO type metadata so ConnectionInterface can bind
 * with the correct PDO parameter type (e.g., PDO::PARAM_LOB for binary data).
 *
 * Binary values (encrypted blobs, blind index hashes) must be bound with
 * PDO::PARAM_LOB for portable storage across MySQL, MariaDB, PostgreSQL, and SQLite.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Param
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * Create a binary parameter for PDO::PARAM_LOB binding.
     *
     * Use for encrypted blobs, blind index hashes, and any raw binary data
     * that must be stored portably across all supported database drivers.
     */
    #[NoDiscard]
    public static function binary(string $bytes): self
    {
        return new self($bytes);
    }

    /**
     * Get the raw value bytes.
     */
    public function bytes(): string
    {
        return $this->value;
    }

    /**
     * Get the PDO parameter type for binding.
     */
    public function pdoType(): int
    {
        return PDO::PARAM_LOB;
    }
}
