<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Tickets\Internal\Service\TicketNumberGenerator;

#[CoversClass(TicketNumberGenerator::class)]
final class TicketNumberGeneratorTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
    }

    #[Test]
    public function nextGeneratesFormattedTicketNumber(): void
    {
        $year = (int) date('Y');

        $result = new Result([new Row(['current_number' => 42])]);

        $this->connection->method('execute')->willReturn(1);
        $this->connection->method('query')->willReturn($result);

        $generator = new TicketNumberGenerator($this->connection);
        $number = $generator->next();

        self::assertSame("TKT-{$year}-000042", $number);
    }

    #[Test]
    public function nextDefaultsToOneWhenNoCounterRow(): void
    {
        $year = (int) date('Y');

        $emptyResult = new Result([]);

        $this->connection->method('execute')->willReturn(1);
        $this->connection->method('query')->willReturn($emptyResult);

        $generator = new TicketNumberGenerator($this->connection);
        $number = $generator->next();

        self::assertSame("TKT-{$year}-000001", $number);
    }

    #[Test]
    public function nextPadsNumberToSixDigits(): void
    {
        $result = new Result([new Row(['current_number' => 1])]);

        $this->connection->method('execute')->willReturn(1);
        $this->connection->method('query')->willReturn($result);

        $generator = new TicketNumberGenerator($this->connection);
        $number = $generator->next();

        self::assertMatchesRegularExpression('/^TKT-\d{4}-\d{6}$/', $number);
    }

    #[Test]
    public function nextHandlesLargeNumbers(): void
    {
        $year = (int) date('Y');

        $result = new Result([new Row(['current_number' => 999999])]);

        $this->connection->method('execute')->willReturn(1);
        $this->connection->method('query')->willReturn($result);

        $generator = new TicketNumberGenerator($this->connection);
        $number = $generator->next();

        self::assertSame("TKT-{$year}-999999", $number);
    }
}
