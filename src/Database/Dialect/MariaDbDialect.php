<?php

declare(strict_types=1);

namespace Pulsar\Database\Dialect;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\DriverVariant;

/**
 * MariaDB.
 *
 * Reached through the MySQL PDO driver and told apart by its `VERSION()` string, which is
 * why it is a {@see DriverVariant} rather than a {@see \Pulsar\Database\Driver} case: at
 * the layer `Driver` describes — which driver opens the connection — MariaDB is MySQL.
 *
 * It differs from MySQL in two ways this framework relies on, and inherits the rest.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MariaDbDialect extends MySqlDialect
{
    #[Override]
    public function variant(): DriverVariant
    {
        return DriverVariant::MariaDb;
    }

    /**
     * MariaDB supports `INSERT … RETURNING` from 10.5; MySQL supports it at no version.
     *
     * The version boundary is deliberately not checked here — a dialect has no connection
     * to ask. A caller that must know what the server in front of it actually does asks
     * {@see \Pulsar\Database\Schema\SchemaCapabilities}, which reads the live version.
     */
    #[Override]
    public function supportsReturning(): bool
    {
        return true;
    }

    /**
     * MariaDB accepts `IF NOT EXISTS` on `CREATE INDEX`; MySQL does not.
     */
    #[Override]
    public function supportsIndexIfNotExists(): bool
    {
        return true;
    }

    #[Override]
    public function name(): string
    {
        return 'mariadb';
    }
}
