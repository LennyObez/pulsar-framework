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
use Pulsar\Http\Validation\Rule\Exists;

#[CoversClass(Exists::class)]
final class ExistsTest extends TestCase
{
    private TableName $table;
    private ColumnName $column;

    protected function setUp(): void
    {
        $this->table = new TableName('categories');
        $this->column = new ColumnName('id');
    }

    #[Test]
    public function existingValuePasses(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        $port->method('exists')->willReturn(true);

        $rule = new Exists($port, $this->table, $this->column);
        self::assertNull($rule->validate('category_id', 42, []));
    }

    #[Test]
    public function nonExistingValueFails(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        $port->method('exists')->willReturn(false);

        $rule = new Exists($port, $this->table, $this->column);
        $violation = $rule->validate('category_id', 999, []);
        self::assertNotNull($violation);
        self::assertSame('exists', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $port = $this->createMock(ValidationQueryPort::class);
        $port->expects(self::never())->method('exists');

        $rule = new Exists($port, $this->table, $this->column);
        self::assertNull($rule->validate('category_id', null, []));
    }

    #[Test]
    public function passesCorrectArgumentsToPort(): void
    {
        $exclude = new WhereConditions();
        $port = $this->createMock(ValidationQueryPort::class);
        $port->expects(self::once())
            ->method('exists')
            ->with($this->table, $this->column, 42, $exclude)
            ->willReturn(true);

        $rule = new Exists($port, $this->table, $this->column, $exclude);
        $rule->validate('category_id', 42, []);
    }

    #[Test]
    public function customMessage(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        $port->method('exists')->willReturn(false);

        $rule = new Exists($port, $this->table, $this->column, message: 'Not found.');
        $violation = $rule->validate('category_id', 999, []);
        self::assertNotNull($violation);
        self::assertSame('Not found.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        $port = $this->createStub(ValidationQueryPort::class);
        self::assertSame('exists', new Exists($port, $this->table, $this->column)->name());
    }
}
