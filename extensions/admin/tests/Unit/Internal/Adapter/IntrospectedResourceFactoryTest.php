<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Internal\Adapter\IntrospectedResource;
use Pulsar\Extension\Admin\Internal\Adapter\IntrospectedResourceFactory;

#[CoversClass(IntrospectedResourceFactory::class)]
#[CoversClass(IntrospectedResource::class)]
final class IntrospectedResourceFactoryTest extends TestCase
{
    private PdoConnection $connection;
    private IntrospectedResourceFactory $factory;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, is_active INTEGER DEFAULT 1, created_at TEXT)');
        $this->connection->execute('CREATE TABLE blog_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT, view_count INTEGER DEFAULT 0)');

        $introspector = new DatabaseIntrospector($this->connection);
        $this->factory = new IntrospectedResourceFactory($introspector);
    }

    #[Test]
    public function discoverAllReturnsResourcesForAllTables(): void
    {
        $resources = $this->factory->discoverAll();

        self::assertCount(2, $resources);
        self::assertContainsOnlyInstancesOf(DataResourceInterface::class, $resources);

        $names = array_map(static fn(DataResourceInterface $r): string => $r->name(), $resources);
        self::assertContains('users', $names);
        self::assertContains('blog_posts', $names);
    }

    #[Test]
    public function discoverAllExcludesPrefixedTables(): void
    {
        $this->connection->execute('CREATE TABLE admin_settings (key TEXT, value TEXT)');
        $this->connection->execute('CREATE TABLE studio_events (data TEXT)');
        $this->connection->execute('CREATE TABLE pulsar_migrations (id INTEGER)');

        $resources = $this->factory->discoverAll(['admin_', 'studio_', 'pulsar_']);

        $names = array_map(static fn(DataResourceInterface $r): string => $r->name(), $resources);
        self::assertNotContains('admin_settings', $names);
        self::assertNotContains('studio_events', $names);
        self::assertNotContains('pulsar_migrations', $names);
        self::assertContains('users', $names);
        self::assertContains('blog_posts', $names);
    }

    #[Test]
    public function forTableReturnsCorrectResourceMetadata(): void
    {
        $resource = $this->factory->forTable('users');

        self::assertSame('users', $resource->name());
        self::assertSame('User', $resource->label());
        self::assertSame('Users', $resource->pluralLabel());
        self::assertSame('id', $resource->primaryKey());
        self::assertSame('table', $resource->icon());
        self::assertSame('id', $resource->defaultSortField());
        self::assertSame('ASC', $resource->defaultSortDirection());
        self::assertFalse($resource->auditReads());
    }

    #[Test]
    public function forTableReturnsCorrectFieldDefinitions(): void
    {
        $resource = $this->factory->forTable('users');
        $fields = $resource->fields();

        self::assertCount(5, $fields);

        $idField = $fields[0];
        self::assertSame('id', $idField->name);
        self::assertSame(FieldType::Integer, $idField->type);
        self::assertFalse($idField->editable, 'Primary key should not be editable');
        self::assertFalse($idField->visibleOnForm, 'Primary key should not be visible on form');

        $nameField = $fields[1];
        self::assertSame('name', $nameField->name);
        self::assertSame(FieldType::Text, $nameField->type);
        self::assertTrue($nameField->searchable);

        $emailField = $fields[2];
        self::assertSame('email', $emailField->name);
        self::assertSame(FieldType::Text, $emailField->type);
    }

    #[Test]
    public function forTableSnakeCaseToLabel(): void
    {
        $resource = $this->factory->forTable('blog_posts');

        self::assertSame('Blog Post', $resource->label());
        self::assertSame('Blog Posts', $resource->pluralLabel());
    }

    #[Test]
    public function resourceOperationsIncludeAllCrud(): void
    {
        $resource = $this->factory->forTable('users');

        self::assertContains(ResourceOperation::List, $resource->operations());
        self::assertContains(ResourceOperation::View, $resource->operations());
        self::assertContains(ResourceOperation::Create, $resource->operations());
        self::assertContains(ResourceOperation::Update, $resource->operations());
        self::assertContains(ResourceOperation::Delete, $resource->operations());
        self::assertContains(ResourceOperation::Export, $resource->operations());
    }

    #[Test]
    public function resourceHasBulkDeleteAction(): void
    {
        $resource = $this->factory->forTable('users');
        $bulkActions = $resource->bulkActions();

        self::assertCount(1, $bulkActions);
        self::assertSame('delete', $bulkActions[0]->name);
        self::assertTrue($bulkActions[0]->destructive);
    }

    #[Test]
    public function exportableFieldsExcludesNonExportable(): void
    {
        $resource = $this->factory->forTable('users');
        $exportable = $resource->exportableFields();

        self::assertNotEmpty($exportable);
        self::assertContains('id', $exportable);
        self::assertContains('name', $exportable);
    }
}
