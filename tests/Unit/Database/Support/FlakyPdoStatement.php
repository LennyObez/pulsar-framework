<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Support;

use PDOException;
use PDOStatement;

/**
 * A `PDOStatement` that can be told to fail as though the server had gone away.
 *
 * Registered through `PDO::ATTR_STATEMENT_CLASS`, so `PDO::prepare()` instantiates it
 * and `execute()` runs through it: the failures below arrive at
 * {@see \Pulsar\Database\PdoConnection} through the real PDO call path rather than
 * through a double of the connection itself. That is what makes a reconnect test
 * possible without a server to unplug — SQLite has no socket to drop.
 *
 * The counters are static because PDO owns the instantiation and there is nowhere to
 * inject state; {@see reset()} clears them in `setUp()`.
 */
final class FlakyPdoStatement extends PDOStatement
{
    /** Number of upcoming `prepare()` calls that must fail. */
    public static int $failPrepares = 0;

    /** Number of upcoming `execute()` calls that must fail. */
    public static int $failExecutes = 0;

    /** SQLSTATE and driver code the simulated failure carries. */
    public static string $sqlState = 'HY000';

    public static ?int $driverCode = 2006;

    protected function __construct()
    {
        if (self::$failPrepares > 0) {
            self::$failPrepares--;

            throw self::failure();
        }
    }

    public static function reset(): void
    {
        self::$failPrepares = 0;
        self::$failExecutes = 0;
        self::$sqlState = 'HY000';
        self::$driverCode = 2006;
    }

    /**
     * @param array<array-key, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if (self::$failExecutes > 0) {
            self::$failExecutes--;

            throw self::failure();
        }

        return parent::execute($params);
    }

    private static function failure(): PDOException
    {
        $exception = new PDOException('simulated connection loss');
        $exception->errorInfo = [self::$sqlState, self::$driverCode, 'simulated connection loss'];

        return $exception;
    }
}
