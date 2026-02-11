<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Support;

use Pulsar\Api\Internal;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Internal\Compiler\DialectInterface;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;

use function str_contains;

/**
 * Quotes SQL identifiers using the appropriate dialect.
 */
#[Internal]
final readonly class IdentifierQuoter
{
    private DialectInterface $dialect;

    public function __construct(Driver $driver)
    {
        $this->dialect = self::dialectFor($driver);
    }

    public function quote(string $identifier): string
    {
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier, 2);

            return $this->dialect->quoteIdentifier($parts[0]) . '.' . $this->dialect->quoteIdentifier($parts[1]);
        }

        return $this->dialect->quoteIdentifier($identifier);
    }

    public function dialect(): DialectInterface
    {
        return $this->dialect;
    }

    public static function dialectFor(Driver $driver): DialectInterface
    {
        return match ($driver) {
            Driver::MySQL => new MySqlDialect(),
            Driver::PostgreSQL => new PostgreSqlDialect(),
            Driver::SQLite => new SqliteDialect(),
        };
    }
}
