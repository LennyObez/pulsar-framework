<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use ReflectionClass;

#[CoversNothing]
final class DataResourceInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(DataResourceInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('name'));
        self::assertTrue($reflection->hasMethod('label'));
        self::assertTrue($reflection->hasMethod('pluralLabel'));
        self::assertTrue($reflection->hasMethod('icon'));
        self::assertTrue($reflection->hasMethod('fields'));
        self::assertTrue($reflection->hasMethod('operations'));
        self::assertTrue($reflection->hasMethod('bulkActions'));
        self::assertTrue($reflection->hasMethod('exportableFields'));
        self::assertTrue($reflection->hasMethod('auditReads'));
        self::assertTrue($reflection->hasMethod('primaryKey'));
        self::assertTrue($reflection->hasMethod('defaultSortField'));
        self::assertTrue($reflection->hasMethod('defaultSortDirection'));
    }

    #[Test]
    public function stubReturnsConfiguredValues(): void
    {
        $stub = $this->createStub(DataResourceInterface::class);
        $stub->method('name')->willReturn('users');
        $stub->method('label')->willReturn('User');
        $stub->method('pluralLabel')->willReturn('Users');
        $stub->method('icon')->willReturn('users');
        $stub->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
        ]);
        $stub->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::Create]);
        $stub->method('bulkActions')->willReturn([new BulkAction(name: 'delete', label: 'Delete')]);
        $stub->method('exportableFields')->willReturn(['id']);
        $stub->method('auditReads')->willReturn(false);
        $stub->method('primaryKey')->willReturn('id');
        $stub->method('defaultSortField')->willReturn('id');
        $stub->method('defaultSortDirection')->willReturn('asc');

        self::assertSame('users', $stub->name());
        self::assertSame('User', $stub->label());
        self::assertSame('Users', $stub->pluralLabel());
        self::assertCount(1, $stub->fields());
        self::assertCount(2, $stub->operations());
        self::assertCount(1, $stub->bulkActions());
        self::assertFalse($stub->auditReads());
        self::assertSame('id', $stub->primaryKey());
    }
}
