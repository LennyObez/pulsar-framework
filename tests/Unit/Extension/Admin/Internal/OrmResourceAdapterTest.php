<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceAdapter;

#[CoversClass(OrmResourceAdapter::class)]
final class OrmResourceAdapterTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $adapter = new OrmResourceAdapter(
            resourceName: 'posts',
            resourceLabel: 'Post',
            resourcePluralLabel: 'Posts',
            resourceIcon: 'document',
            tableName: 'cms_posts',
            fields: [],
        );

        self::assertSame('posts', $adapter->name());
        self::assertSame('Post', $adapter->label());
        self::assertSame('Posts', $adapter->pluralLabel());
        self::assertSame('document', $adapter->icon());
        self::assertSame('cms_posts', $adapter->tableName());
        self::assertSame([], $adapter->fields());
        self::assertSame('id', $adapter->primaryKey());
        self::assertSame('id', $adapter->defaultSortField());
        self::assertSame('desc', $adapter->defaultSortDirection());
        self::assertFalse($adapter->auditReads());
    }

    #[Test]
    public function defaultOperationsIncludeAllCrud(): void
    {
        $adapter = new OrmResourceAdapter('users', 'User', 'Users', 'user', 'users', []);

        $ops = $adapter->operations();
        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Create, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
        self::assertContains(ResourceOperation::Export, $ops);
    }

    #[Test]
    public function customOperationsOverrideDefaults(): void
    {
        $adapter = new OrmResourceAdapter(
            resourceName: 'logs',
            resourceLabel: 'Log',
            resourcePluralLabel: 'Logs',
            resourceIcon: 'log',
            tableName: 'logs',
            fields: [],
            operations: [ResourceOperation::List, ResourceOperation::View],
        );

        self::assertCount(2, $adapter->operations());
        self::assertNotContains(ResourceOperation::Delete, $adapter->operations());
    }

    #[Test]
    public function exportableFieldsFromExplicitList(): void
    {
        $adapter = new OrmResourceAdapter(
            resourceName: 'test',
            resourceLabel: 'Test',
            resourcePluralLabel: 'Tests',
            resourceIcon: 'test',
            tableName: 'tests',
            fields: [],
            exportableFields: ['name', 'email'],
        );

        self::assertSame(['name', 'email'], $adapter->exportableFields());
    }

    #[Test]
    public function exportableFieldsDerivedFromFieldDefinitions(): void
    {
        $fields = [
            new FieldDefinition('id', FieldType::Text, 'ID', exportable: true, redacted: false),
            new FieldDefinition('name', FieldType::Text, 'Name', exportable: true, redacted: false),
            new FieldDefinition('ssn', FieldType::Text, 'SSN', exportable: true, redacted: true),
            new FieldDefinition('internal', FieldType::Text, 'Internal', exportable: false, redacted: false),
        ];

        $adapter = new OrmResourceAdapter(
            resourceName: 'users',
            resourceLabel: 'User',
            resourcePluralLabel: 'Users',
            resourceIcon: 'user',
            tableName: 'users',
            fields: $fields,
        );

        $exportable = $adapter->exportableFields();
        self::assertContains('id', $exportable);
        self::assertContains('name', $exportable);
        // redacted fields excluded
        self::assertNotContains('ssn', $exportable);
        // non-exportable fields excluded
        self::assertNotContains('internal', $exportable);
    }

    #[Test]
    public function customSortAndPrimaryKey(): void
    {
        $adapter = new OrmResourceAdapter(
            resourceName: 'events',
            resourceLabel: 'Event',
            resourcePluralLabel: 'Events',
            resourceIcon: 'calendar',
            tableName: 'events',
            fields: [],
            pk: 'event_id',
            sortField: 'created_at',
            sortDirection: 'asc',
        );

        self::assertSame('event_id', $adapter->primaryKey());
        self::assertSame('created_at', $adapter->defaultSortField());
        self::assertSame('asc', $adapter->defaultSortDirection());
    }

    #[Test]
    public function auditReadsEnabled(): void
    {
        $adapter = new OrmResourceAdapter(
            resourceName: 'sensitive',
            resourceLabel: 'Sensitive',
            resourcePluralLabel: 'Sensitive Records',
            resourceIcon: 'lock',
            tableName: 'sensitive',
            fields: [],
            auditReadsEnabled: true,
        );

        self::assertTrue($adapter->auditReads());
    }

    #[Test]
    public function bulkActionsReturnConfiguredList(): void
    {
        $adapter = new OrmResourceAdapter(
            resourceName: 'test',
            resourceLabel: 'Test',
            resourcePluralLabel: 'Tests',
            resourceIcon: 'test',
            tableName: 'tests',
            fields: [],
        );

        self::assertSame([], $adapter->bulkActions());
    }
}
