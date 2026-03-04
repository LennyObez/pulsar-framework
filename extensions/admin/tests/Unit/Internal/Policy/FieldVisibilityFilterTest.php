<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Policy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

final class FieldVisibilityFilterTest extends TestCase
{
    private FieldVisibilityFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new FieldVisibilityFilter();
    }

    #[Test]
    public function filter_for_list_shows_only_list_visible(): void
    {
        $resource = $this->createResource([
            new FieldDefinition('id', FieldType::Integer, 'ID', visibleOnList: true),
            new FieldDefinition('name', FieldType::String, 'Name', visibleOnList: true),
            new FieldDefinition('secret', FieldType::String, 'Secret', visibleOnList: false),
        ]);

        $record = ['id' => 1, 'name' => 'Alice', 'secret' => 'hidden'];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertArrayHasKey('id', $filtered);
        self::assertArrayHasKey('name', $filtered);
        self::assertArrayNotHasKey('secret', $filtered);
    }

    #[Test]
    public function filter_for_detail_shows_only_detail_visible(): void
    {
        $resource = $this->createResource([
            new FieldDefinition('id', FieldType::Integer, 'ID', visibleOnDetail: true),
            new FieldDefinition('summary', FieldType::String, 'Summary', visibleOnDetail: false),
        ]);

        $record = ['id' => 1, 'summary' => 'hidden'];
        $filtered = $this->filter->filterForDetail($resource, $record);

        self::assertArrayHasKey('id', $filtered);
        self::assertArrayNotHasKey('summary', $filtered);
    }

    #[Test]
    public function redacted_fields_replaced_with_placeholder(): void
    {
        $resource = $this->createResource([
            new FieldDefinition('name', FieldType::String, 'Name'),
            new FieldDefinition('ssn', FieldType::String, 'SSN', visibleOnList: true, redacted: true),
        ]);

        $record = ['name' => 'Alice', 'ssn' => '123-45-6789'];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertSame('Alice', $filtered['name']);
        self::assertSame("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}", $filtered['ssn']);
    }

    #[Test]
    public function primary_key_always_included(): void
    {
        $resource = $this->createResource([
            new FieldDefinition('name', FieldType::String, 'Name', visibleOnList: true),
        ], 'id');

        $record = ['id' => 42, 'name' => 'Alice'];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertSame(42, $filtered['id']);
        self::assertSame('Alice', $filtered['name']);
    }

    #[Test]
    public function filter_for_export_excludes_redacted(): void
    {
        $resource = $this->createResource(
            [
                new FieldDefinition('name', FieldType::String, 'Name', exportable: true),
                new FieldDefinition('password', FieldType::String, 'Password', exportable: true, redacted: true),
                new FieldDefinition('internal', FieldType::String, 'Internal', exportable: false),
            ],
            'id',
            ['name', 'password'],
        );

        $record = ['name' => 'Alice', 'password' => 'secret', 'internal' => 'data'];
        $filtered = $this->filter->filterForExport($resource, $record);

        self::assertArrayHasKey('name', $filtered);
        self::assertArrayNotHasKey('password', $filtered);
        self::assertArrayNotHasKey('internal', $filtered);
    }

    #[Test]
    public function filter_for_export_converts_non_scalar_to_json(): void
    {
        $resource = $this->createResource(
            [new FieldDefinition('data', FieldType::Json, 'Data', exportable: true)],
            'id',
            ['data'],
        );

        $record = ['data' => ['key' => 'value']];
        $filtered = $this->filter->filterForExport($resource, $record);

        self::assertSame('{"key":"value"}', $filtered['data']);
    }

    #[Test]
    public function missing_record_values_are_null(): void
    {
        $resource = $this->createResource([
            new FieldDefinition('name', FieldType::String, 'Name', visibleOnList: true),
        ]);

        $record = [];
        $filtered = $this->filter->filterForList($resource, $record);

        self::assertNull($filtered['name']);
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param list<string> $exportableFields
     */
    private function createResource(
        array $fields,
        string $primaryKey = 'id',
        array $exportableFields = [],
    ): DataResourceInterface&Stub {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('fields')->willReturn($fields);
        $resource->method('primaryKey')->willReturn($primaryKey);
        $resource->method('exportableFields')->willReturn(
            $exportableFields ?: array_map(static fn(FieldDefinition $f): string => $f->name, $fields),
        );

        return $resource;
    }
}
