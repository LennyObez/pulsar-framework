<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Adapter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceQuery;

#[CoversClass(OrmResourceQuery::class)]
final class OrmResourceQuerySortInjectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousSortFieldProvider(): iterable
    {
        yield 'SQL injection via semicolon' => ['id; DROP TABLE users--'];
        yield 'SQL injection via subquery' => ['(SELECT password FROM users LIMIT 1)'];
        yield 'single quote injection' => ["id' OR '1'='1"];
        yield 'double dash comment' => ['id: comment'];
        yield 'unicode null byte' => ["id\x00"];
        yield 'backtick escape' => ['id` FROM users; --'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousUserSortFieldProvider(): iterable
    {
        yield 'dotted table-qualified' => ['users.password'];
        yield 'SQL injection' => ['1; DROP TABLE--'];
        yield 'subquery' => ['(SELECT 1)'];
    }

    #[Test]
    #[DataProvider('maliciousSortFieldProvider')]
    public function rejectsMaliciousDefaultSortField(string $maliciousField): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('items');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn($maliciousField);
        $resource->method('defaultSortDirection')->willReturn('ASC');
        $resource->method('fields')->willReturn([]);

        $query = new OrmResourceQuery($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQL identifier');
        $query->list($resource);
    }

    #[Test]
    public function safeDefaultSortFieldIsQuoted(): void
    {
        $row = $this->createStub(\Pulsar\Database\Row::class);
        $row->method('get')->willReturn(0);
        $row->method('toArray')->willReturn([]);

        $countResult = new Result([$row]);

        $capturedSql = '';
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnCallback(
            function (string $sql) use (&$capturedSql, $countResult): Result {
                $capturedSql = $sql;
                return $countResult;
            },
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('items');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn('created_at');
        $resource->method('defaultSortDirection')->willReturn('DESC');
        $resource->method('fields')->willReturn([]);

        $query = new OrmResourceQuery($connection);
        $query->list($resource);

        // The sort field must be backtick-quoted in the SQL
        self::assertStringContainsString('`created_at`', $capturedSql);
    }

    #[Test]
    #[DataProvider('maliciousUserSortFieldProvider')]
    public function rejectsMaliciousUserSuppliedSortField(string $maliciousField): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $field = new FieldDefinition(
            name: $maliciousField,
            type: FieldType::Text,
            label: 'Unsafe',
            sortable: true,
            filterable: false,
            searchable: false,
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('items');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn('id');
        $resource->method('defaultSortDirection')->willReturn('ASC');
        $resource->method('fields')->willReturn([$field]);

        $query = new OrmResourceQuery($connection);

        $this->expectException(InvalidArgumentException::class);
        $query->list($resource, sort: [$maliciousField => 'ASC']);
    }

    #[Test]
    public function validUserSortFieldIsQuoted(): void
    {
        $row = $this->createStub(\Pulsar\Database\Row::class);
        $row->method('get')->willReturn(0);
        $row->method('toArray')->willReturn([]);

        $countResult = new Result([$row]);

        $capturedSql = '';
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnCallback(
            function (string $sql) use (&$capturedSql, $countResult): Result {
                $capturedSql = $sql;
                return $countResult;
            },
        );

        $field = new FieldDefinition(
            name: 'email',
            type: FieldType::Text,
            label: 'Email',
            sortable: true,
            filterable: false,
            searchable: false,
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('items');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn('id');
        $resource->method('defaultSortDirection')->willReturn('ASC');
        $resource->method('fields')->willReturn([$field]);

        $query = new OrmResourceQuery($connection);
        $query->list($resource, sort: ['email' => 'DESC']);

        self::assertStringContainsString('`email` DESC', $capturedSql);
    }
}
