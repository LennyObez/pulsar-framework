<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

#[CoversClass(FieldVisibilityFilter::class)]
final class FieldVisibilityFilterTest extends TestCase
{
    private FieldVisibilityFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new FieldVisibilityFilter();
    }

    #[Test]
    public function filterForListHidesNonListVisibleFields(): void
    {
        $resource = $this->createResource([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID', visibleOnList: true),
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name', visibleOnList: true),
            new FieldDefinition(name: 'internal_notes', type: FieldType::Text, label: 'Notes', visibleOnList: false),
        ]);

        $record = ['id' => 1, 'name' => 'Alice', 'internal_notes' => 'secret notes'];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertArrayHasKey('id', $filtered);
        self::assertArrayHasKey('name', $filtered);
        self::assertArrayNotHasKey('internal_notes', $filtered);
    }

    #[Test]
    public function filterForListRedactsMarkedFields(): void
    {
        $resource = $this->createResource([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
            new FieldDefinition(name: 'ssn', type: FieldType::String, label: 'SSN', redacted: true),
        ]);

        $record = ['id' => 1, 'ssn' => '123-45-6789'];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertSame(1, $filtered['id']);
        self::assertSame('••••••', $filtered['ssn']);
    }

    #[Test]
    public function filterForDetailHidesNonDetailVisibleFields(): void
    {
        $resource = $this->createResource([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID', visibleOnDetail: true),
            new FieldDefinition(name: 'password_hash', type: FieldType::String, label: 'Password', visibleOnDetail: false),
        ]);

        $record = ['id' => 1, 'password_hash' => '$2y$...'];
        $filtered = $this->filter->filterForDetail($resource, $record);

        self::assertArrayHasKey('id', $filtered);
        self::assertArrayNotHasKey('password_hash', $filtered);
    }

    #[Test]
    public function filterForDetailRedactsMarkedFields(): void
    {
        $resource = $this->createResource([
            new FieldDefinition(name: 'email', type: FieldType::Email, label: 'Email', redacted: true, visibleOnDetail: true),
        ]);

        $record = ['email' => 'alice@example.com'];
        $filtered = $this->filter->filterForDetail($resource, $record);

        self::assertSame('••••••', $filtered['email']);
    }

    #[Test]
    public function filterForExportExcludesRedactedFields(): void
    {
        $resource = $this->createResource(
            fields: [
                new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
                new FieldDefinition(name: 'ssn', type: FieldType::String, label: 'SSN', redacted: true),
                new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
            ],
            exportableFields: ['id', 'ssn', 'name'],
        );

        $record = ['id' => 1, 'ssn' => '123-45-6789', 'name' => 'Alice'];
        $filtered = $this->filter->filterForExport($resource, $record);

        self::assertArrayHasKey('id', $filtered);
        self::assertArrayHasKey('name', $filtered);
        self::assertArrayNotHasKey('ssn', $filtered);
    }

    #[Test]
    public function filterForExportOnlyIncludesExportableFields(): void
    {
        $resource = $this->createResource(
            fields: [
                new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
                new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
                new FieldDefinition(name: 'internal', type: FieldType::String, label: 'Internal'),
            ],
            exportableFields: ['id', 'name'],
        );

        $record = ['id' => 1, 'name' => 'Alice', 'internal' => 'secret'];
        $filtered = $this->filter->filterForExport($resource, $record);

        self::assertArrayHasKey('id', $filtered);
        self::assertArrayHasKey('name', $filtered);
        self::assertArrayNotHasKey('internal', $filtered);
    }

    #[Test]
    public function filterForListIncludesPrimaryKeyIfMissing(): void
    {
        $resource = $this->createResource(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name', visibleOnList: true),
            ],
            primaryKey: 'id',
        );

        $record = ['id' => 42, 'name' => 'Alice'];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertSame(42, $filtered['id']);
        self::assertSame('Alice', $filtered['name']);
    }

    #[Test]
    public function filterForExportConvertsNonScalarToJson(): void
    {
        $resource = $this->createResource(
            fields: [
                new FieldDefinition(name: 'data', type: FieldType::Json, label: 'Data'),
            ],
            exportableFields: ['data'],
        );

        $record = ['data' => ['nested' => 'value']];
        $filtered = $this->filter->filterForExport($resource, $record);

        self::assertSame('{"nested":"value"}', $filtered['data']);
    }

    #[Test]
    public function filterForExportPassesThroughScalars(): void
    {
        $resource = $this->createResource(
            fields: [
                new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
                new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
                new FieldDefinition(name: 'active', type: FieldType::Boolean, label: 'Active'),
                new FieldDefinition(name: 'score', type: FieldType::Float, label: 'Score'),
                new FieldDefinition(name: 'notes', type: FieldType::Text, label: 'Notes'),
            ],
            exportableFields: ['id', 'name', 'active', 'score', 'notes'],
        );

        $record = ['id' => 1, 'name' => 'Alice', 'active' => true, 'score' => 9.5, 'notes' => null];
        $filtered = $this->filter->filterForExport($resource, $record);

        self::assertSame(1, $filtered['id']);
        self::assertSame('Alice', $filtered['name']);
        self::assertTrue($filtered['active']);
        self::assertSame(9.5, $filtered['score']);
        self::assertNull($filtered['notes']);
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param list<string> $exportableFields
     */
    private function createResource(
        array $fields,
        array $exportableFields = [],
        string $primaryKey = 'id',
    ): DataResourceInterface {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('fields')->willReturn($fields);
        $resource->method('exportableFields')->willReturn($exportableFields);
        $resource->method('primaryKey')->willReturn($primaryKey);

        return $resource;
    }

    #[Test]
    public function missingRecordValuesAreNull(): void
    {
        $resource = $this->createResource([
            new FieldDefinition('name', FieldType::String, 'Name', visibleOnList: true),
        ]);

        $filtered = $this->filter->filterForList($resource, []);

        self::assertNull($filtered['name']);
    }
}
