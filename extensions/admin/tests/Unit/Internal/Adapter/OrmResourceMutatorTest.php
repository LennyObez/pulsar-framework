<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceMutator;
use Pulsar\Security\Audit\AuditEntry;
use RuntimeException;

#[CoversClass(OrmResourceMutator::class)]
final class OrmResourceMutatorTest extends TestCase
{
    /** @var ConnectionInterface&Stub */
    private ConnectionInterface $connection;
    private AuditLoggerInterface $auditLogger;
    private OrmResourceMutator $mutator;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        /** @var AuditLoggerInterface&Stub $auditLogger */
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger = $auditLogger;

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger->method('log')->willReturn($auditEntry);

        $this->mutator = new OrmResourceMutator($this->connection, $this->auditLogger);
    }

    private function makeResource(): DataResourceInterface
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('label')->willReturn('User');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('fields')->willReturn([
            new FieldDefinition('name', FieldType::String, 'Name', editable: true),
            new FieldDefinition('email', FieldType::Email, 'Email', editable: true),
            new FieldDefinition('id', FieldType::Integer, 'ID', editable: false),
        ]);
        $resource->method('bulkActions')->willReturn([
            new BulkAction('delete', 'Delete', destructive: true),
        ]);

        return $resource;
    }

    private function makeContext(): MutationContext
    {
        return new MutationContext(actor: 'admin', reason: 'Test operation');
    }

    #[Test]
    public function createSuccessfully(): void
    {
        $this->connection->method('execute')->willReturn(1);
        $this->connection->method('lastInsertId')->willReturn('1');

        $result = $this->mutator->create(
            $this->makeResource(),
            ['name' => 'John', 'email' => 'john@test.com'],
            $this->makeContext(),
        );

        self::assertTrue($result->success);
        self::assertStringContainsString('Created User #1', $result->message);
        self::assertSame('1', $result->metadata['id']);
    }

    #[Test]
    public function createFailsWithNoValidFields(): void
    {
        $result = $this->mutator->create(
            $this->makeResource(),
            ['id' => 1, 'nonexistent' => 'value'],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('No valid fields', $result->message);
    }

    #[Test]
    public function createFailsOnDatabaseException(): void
    {
        $this->connection->method('execute')
            ->willThrowException(new RuntimeException('DB error'));

        $result = $this->mutator->create(
            $this->makeResource(),
            ['name' => 'John', 'email' => 'john@test.com'],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('Failed to create', $result->message);
        self::assertStringContainsString('DB error', $result->message);
    }

    #[Test]
    public function updateSuccessfully(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $result = $this->mutator->update(
            $this->makeResource(),
            '42',
            ['name' => 'Updated Name'],
            $this->makeContext(),
        );

        self::assertTrue($result->success);
        self::assertStringContainsString('Updated User #42', $result->message);
    }

    #[Test]
    public function updateFailsWithNoValidFields(): void
    {
        $result = $this->mutator->update(
            $this->makeResource(),
            '42',
            ['unknown_field' => 'value'],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('No valid fields', $result->message);
    }

    #[Test]
    public function updateReturnsNotFoundWhenNoRowsAffected(): void
    {
        $this->connection->method('execute')->willReturn(0);

        $result = $this->mutator->update(
            $this->makeResource(),
            '999',
            ['name' => 'Updated'],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('#999 not found', $result->message);
    }

    #[Test]
    public function updateFailsOnDatabaseException(): void
    {
        $this->connection->method('execute')
            ->willThrowException(new RuntimeException('Constraint violation'));

        $result = $this->mutator->update(
            $this->makeResource(),
            '42',
            ['name' => 'Name'],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('Failed to update', $result->message);
    }

    #[Test]
    public function deleteSuccessfully(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $result = $this->mutator->delete(
            $this->makeResource(),
            '42',
            $this->makeContext(),
        );

        self::assertTrue($result->success);
        self::assertStringContainsString('Deleted User #42', $result->message);
    }

    #[Test]
    public function deleteReturnsNotFoundWhenNoRowsAffected(): void
    {
        $this->connection->method('execute')->willReturn(0);

        $result = $this->mutator->delete(
            $this->makeResource(),
            '999',
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('#999 not found', $result->message);
    }

    #[Test]
    public function deleteFailsOnDatabaseException(): void
    {
        $this->connection->method('execute')
            ->willThrowException(new RuntimeException('FK constraint'));

        $result = $this->mutator->delete(
            $this->makeResource(),
            '42',
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('Failed to delete', $result->message);
    }

    #[Test]
    public function bulkActionFailsWithEmptyIds(): void
    {
        $result = $this->mutator->bulkAction(
            $this->makeResource(),
            'delete',
            [],
            [],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('No records selected', $result->message);
    }

    #[Test]
    public function bulkActionFailsWithUnsupportedAction(): void
    {
        $result = $this->mutator->bulkAction(
            $this->makeResource(),
            'archive',
            ['1', '2'],
            [],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('Unsupported bulk action', $result->message);
    }

    #[Test]
    public function bulkDeleteSuccessfully(): void
    {
        $this->connection->method('execute')->willReturn(3);

        $result = $this->mutator->bulkAction(
            $this->makeResource(),
            'delete',
            ['1', '2', '3'],
            [],
            $this->makeContext(),
        );

        self::assertTrue($result->success);
        self::assertStringContainsString('3 record(s) affected', $result->message);
    }

    #[Test]
    public function bulkDeleteFailsOnException(): void
    {
        $this->connection->method('execute')
            ->willThrowException(new RuntimeException('Bulk error'));

        $result = $this->mutator->bulkAction(
            $this->makeResource(),
            'delete',
            ['1', '2'],
            [],
            $this->makeContext(),
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('failed', $result->message);
    }
}
