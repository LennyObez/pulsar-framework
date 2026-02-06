<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Contract\ColumnName;
use Pulsar\Http\Validation\Contract\TableName;
use Pulsar\Http\Validation\Contract\ValidationQueryPort;
use Pulsar\Http\Validation\Contract\WhereConditions;
use Pulsar\Http\Validation\Rule\Unique;

#[CoversClass(Unique::class)]
final class UniqueTest extends TestCase
{
    private TableName $table;
    private ColumnName $column;

    protected function setUp(): void
    {
        $this->table = new TableName('users');
        $this->column = new ColumnName('email');
    }

    #[Test]
    public function uniqueValuePasses(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        $port->method('isUnique')->willReturn(true);

        $rule = new Unique($port, $this->table, $this->column);
        self::assertNull($rule->validate('email', 'new@example.com', []));
    }

    #[Test]
    public function duplicateValueFails(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        $port->method('isUnique')->willReturn(false);

        $rule = new Unique($port, $this->table, $this->column);
        $violation = $rule->validate('email', 'taken@example.com', []);
        self::assertNotNull($violation);
        self::assertSame('unique', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $port = $this->createMock(ValidationQueryPort::class);
        $port->expects(self::never())->method('isUnique');

        $rule = new Unique($port, $this->table, $this->column);
        self::assertNull($rule->validate('email', null, []));
    }

    #[Test]
    public function passesCorrectArgumentsToPort(): void
    {
        $exclude = new WhereConditions();
        $port = $this->createMock(ValidationQueryPort::class);
        $port->expects(self::once())
            ->method('isUnique')
            ->with($this->table, $this->column, 'test@example.com', $exclude)
            ->willReturn(true);

        $rule = new Unique($port, $this->table, $this->column, $exclude);
        $rule->validate('email', 'test@example.com', []);
    }

    #[Test]
    public function customMessage(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        $port->method('isUnique')->willReturn(false);

        $rule = new Unique($port, $this->table, $this->column, message: 'Already exists.');
        $violation = $rule->validate('email', 'taken@example.com', []);
        self::assertNotNull($violation);
        self::assertSame('Already exists.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        self::assertSame('unique', new Unique($port, $this->table, $this->column)->name());
    }
}
