<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use Exception;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\LostConnection;
use ReflectionProperty;

#[CoversClass(LostConnection::class)]
final class LostConnectionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: int|null}>
     */
    public static function lostConnectionErrors(): iterable
    {
        yield 'postgres connection_failure' => ['08006', null];
        yield 'postgres connection_does_not_exist' => ['08003', null];
        yield 'sql client unable to establish' => ['08001', null];
        yield 'connection failure during transaction' => ['08007', null];
        yield 'postgres admin shutdown' => ['57P01', null];
        yield 'postgres crash shutdown' => ['57P02', null];
        yield 'postgres cannot connect now' => ['57P03', null];
        yield 'mysql server has gone away' => ['HY000', 2006];
        yield 'mysql lost connection during query' => ['HY000', 2013];
        yield 'mysql lost connection system error' => ['HY000', 2055];
        yield 'mysql client disconnected for inactivity' => ['HY000', 4031];
    }

    #[Test]
    #[DataProvider('lostConnectionErrors')]
    public function aDeadConnectionIsRecognised(string $sqlState, ?int $driverCode): void
    {
        self::assertTrue(LostConnection::occurred($this->pdoException($sqlState, $driverCode)));
    }

    /**
     * @return iterable<string, array{0: string, 1: int|null}>
     */
    public static function healthyConnectionErrors(): iterable
    {
        yield 'deadlock' => ['40001', 1213];
        yield 'lock wait timeout' => ['HY000', 1205];
        yield 'duplicate key' => ['23000', 1062];
        yield 'syntax error' => ['42000', 1064];
        yield 'unknown table' => ['42S02', 1146];
        yield 'sqlite busy' => ['HY000', 5];
        yield 'sqlite no such table' => ['HY000', 1];
        yield 'access denied' => ['28000', 1045];
    }

    /**
     * A query that failed is not a connection that died. Discarding the handle for a
     * deadlock or a constraint violation would drop a healthy connection on every
     * application bug — and, worse, would reconnect in the middle of a transaction the
     * caller is still holding.
     */
    #[Test]
    #[DataProvider('healthyConnectionErrors')]
    public function aFailedStatementOnALiveConnectionIsNot(string $sqlState, ?int $driverCode): void
    {
        self::assertFalse(LostConnection::occurred($this->pdoException($sqlState, $driverCode)));
    }

    /**
     * `new PDO()` failures arrive with no `errorInfo`; PDO puts the SQLSTATE in the
     * exception code instead.
     */
    #[Test]
    public function theExceptionCodeIsReadWhenThereIsNoErrorInfo(): void
    {
        $withoutInfo = new PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
        self::assertFalse(LostConnection::occurred($withoutInfo));

        $withCode = new PDOException('server closed the connection unexpectedly');
        $reflection = new ReflectionProperty(Exception::class, 'code');
        $reflection->setValue($withCode, '08006');

        self::assertTrue(LostConnection::occurred($withCode));
    }

    private function pdoException(string $sqlState, ?int $driverCode): PDOException
    {
        $exception = new PDOException('simulated');
        $exception->errorInfo = [$sqlState, $driverCode, 'simulated'];

        return $exception;
    }
}
