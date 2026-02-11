<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

#[CoversClass(FieldVisibilityFilter::class)]
final class FieldVisibilityFilterDeepTest extends TestCase
{
    private FieldVisibilityFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new FieldVisibilityFilter();
    }

    #[Test]
    public function filterForExportExcludesNonExportableFields(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name'),
                new FieldDefinition(name: 'secret', type: FieldType::Text, label: 'Secret', redacted: true),
                new FieldDefinition(name: 'email', type: FieldType::Email, label: 'Email'),
            ],
            exportableFields: ['name', 'secret', 'email'],
        );

        $record = ['name' => 'John', 'secret' => 'classified', 'email' => 'john@test.com'];
        $result = $this->filter->filterForExport($resource, $record);

        // Redacted fields are stripped entirely in export
        self::assertArrayNotHasKey('secret', $result);
        self::assertSame('John', $result['name']);
        self::assertSame('john@test.com', $result['email']);
    }

    #[Test]
    public function filterForExportOnlyIncludesExportableFields(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name'),
                new FieldDefinition(name: 'internal', type: FieldType::Text, label: 'Internal'),
            ],
            exportableFields: ['name'],
        );

        $record = ['name' => 'John', 'internal' => 'data'];
        $result = $this->filter->filterForExport($resource, $record);

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('internal', $result);
    }

    #[Test]
    public function filterForExportConvertsNonScalarToJson(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'meta', type: FieldType::Text, label: 'Meta'),
            ],
            exportableFields: ['meta'],
        );

        $record = ['meta' => ['nested' => 'value']];
        $result = $this->filter->filterForExport($resource, $record);

        self::assertSame('{"nested":"value"}', $result['meta']);
    }

    #[Test]
    public function filterForExportHandlesMissingField(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name'),
            ],
            exportableFields: ['name'],
        );

        $record = [];
        $result = $this->filter->filterForExport($resource, $record);

        self::assertNull($result['name']);
    }

    #[Test]
    public function filterForListReplacesRedactedValues(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name', visibleOnList: true),
                new FieldDefinition(name: 'ssn', type: FieldType::Text, label: 'SSN', visibleOnList: true, redacted: true),
            ],
        );

        $record = ['id' => '1', 'name' => 'John', 'ssn' => '123-45-6789'];
        $result = $this->filter->filterForList($resource, $record);

        self::assertSame('John', $result['name']);
        self::assertSame('••••••', $result['ssn']);
    }

    #[Test]
    public function filterForListExcludesNonVisibleFields(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name', visibleOnList: true),
                new FieldDefinition(name: 'hidden', type: FieldType::Text, label: 'Hidden', visibleOnList: false),
            ],
        );

        $record = ['id' => '1', 'name' => 'John', 'hidden' => 'secret'];
        $result = $this->filter->filterForList($resource, $record);

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('hidden', $result);
    }

    #[Test]
    public function filterForDetailUsesDetailVisibility(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name', visibleOnDetail: true),
                new FieldDefinition(name: 'internal', type: FieldType::Text, label: 'Internal', visibleOnDetail: false),
            ],
        );

        $record = ['id' => '1', 'name' => 'John', 'internal' => 'data'];
        $result = $this->filter->filterForDetail($resource, $record);

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('internal', $result);
    }

    #[Test]
    public function filterAlwaysIncludesPrimaryKeyIfMissing(): void
    {
        $resource = $this->createResourceStub(
            fields: [
                new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name', visibleOnList: true),
            ],
        );

        // Primary key 'id' is not in the fields list, but exists in record
        $record = ['id' => '42', 'name' => 'John'];
        $result = $this->filter->filterForList($resource, $record);

        // PK should be added even if not in visible fields
        self::assertSame('42', $result['id']);
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param list<string> $exportableFields
     */
    private function createResourceStub(
        array $fields = [],
        array $exportableFields = [],
    ): DataResourceInterface {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('fields')->willReturn($fields);
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('exportableFields')->willReturn($exportableFields);

        return $resource;
    }
}
