<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ContentType;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ContentType\SchemaBuilder;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeRegistryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

use function count;

final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $builder;
    private ContentTypeRegistryInterface&Stub $registry;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ContentTypeRegistryInterface::class);
        $this->builder = new SchemaBuilder($this->registry);
    }

    #[Test]
    public function buildFromInputCreatesValidDefinition(): void
    {
        $input = [
            'type' => 'project',
            'label' => 'Project',
            'icon' => 'folder',
            'fields' => [
                ['key' => 'tagline', 'type' => 'string', 'required' => true, 'translatable' => true],
                ['key' => 'featured', 'type' => 'bool', 'default' => false],
                ['key' => 'priority', 'type' => 'int', 'sortable' => true],
            ],
        ];

        $definition = $this->builder->buildFromInput($input);

        self::assertSame('project', $definition->type);
        self::assertSame('Project', $definition->label);
        self::assertSame('folder', $definition->icon);
        self::assertCount(3, $definition->fields);
        self::assertSame('tagline', $definition->fields[0]->fieldKey);
        self::assertSame(FieldType::String, $definition->fields[0]->fieldType);
        self::assertTrue($definition->fields[0]->required);
        self::assertTrue($definition->fields[0]->translatable);
        self::assertSame('featured', $definition->fields[1]->fieldKey);
        self::assertSame(FieldType::Bool, $definition->fields[1]->fieldType);
    }

    #[Test]
    public function buildFromInputRejectsMissingType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'type' is required");

        $this->builder->buildFromInput(['label' => 'Test', 'fields' => []]);
    }

    #[Test]
    public function buildFromInputRejectsMissingLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'label' is required");

        $this->builder->buildFromInput(['type' => 'test_type', 'fields' => []]);
    }

    #[Test]
    #[DataProvider('invalidSlugProvider')]
    public function buildFromInputRejectsInvalidSlug(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->buildFromInput([
            'type' => $slug,
            'label' => 'Test',
            'fields' => [],
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'starts with number' => ['1invalid'];
        yield 'contains spaces' => ['has spaces'];
        yield 'too short' => ['ab'];
        yield 'uppercase' => ['HasUpperCase'];
        yield 'special chars' => ['has-dashes'];
        yield 'single char' => ['x'];
    }

    #[Test]
    public function buildFromInputRejectsDuplicateFieldKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate field key');

        $this->builder->buildFromInput([
            'type' => 'test_type',
            'label' => 'Test',
            'fields' => [
                ['key' => 'name', 'type' => 'string'],
                ['key' => 'name', 'type' => 'string'],
            ],
        ]);
    }

    #[Test]
    public function buildFromInputRejectsInvalidFieldType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field type');

        $this->builder->buildFromInput([
            'type' => 'test_type',
            'label' => 'Test',
            'fields' => [
                ['key' => 'bad', 'type' => 'nonexistent_type'],
            ],
        ]);
    }

    #[Test]
    public function buildAndRegisterPersistsDefinition(): void
    {
        $this->registry->method('get')->willReturn(null);

        $input = [
            'type' => 'article',
            'label' => 'Article',
            'icon' => 'file-text',
            'fields' => [
                ['key' => 'summary', 'type' => 'string', 'searchable' => true],
            ],
        ];

        $definition = $this->builder->buildAndRegister($input);

        self::assertSame('article', $definition->type);
        self::assertCount(1, $definition->fields);
    }

    #[Test]
    public function buildAndRegisterRejectsDuplicateType(): void
    {
        $existing = new ContentTypeDefinition('article', 'Article', 'file', []);
        $this->registry->method('get')->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        $this->builder->buildAndRegister([
            'type' => 'article',
            'label' => 'Article',
            'fields' => [],
        ]);
    }

    #[Test]
    public function updateSchemaRejectsNonexistentType(): void
    {
        $this->registry->method('get')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->builder->updateSchema('nonexistent', ['label' => 'New', 'fields' => []]);
    }

    #[Test]
    public function serializeProducesPortableArray(): void
    {
        $definition = $this->builder->buildFromInput([
            'type' => 'event_type',
            'label' => 'Event',
            'icon' => 'calendar',
            'fields' => [
                ['key' => 'venue', 'type' => 'string', 'required' => true],
                ['key' => 'capacity', 'type' => 'int', 'sortable' => true],
            ],
        ]);

        $serialized = $this->builder->serialize($definition);

        self::assertSame('event_type', $serialized['type']);
        self::assertSame('Event', $serialized['label']);
        self::assertCount(2, $serialized['fields']);
        self::assertSame('venue', $serialized['fields'][0]['key']);
        self::assertSame('string', $serialized['fields'][0]['type']);
        self::assertTrue($serialized['fields'][0]['required']);
    }

    #[Test]
    public function buildFromInputHandlesAllFieldTypes(): void
    {
        $fields = [];

        foreach (FieldType::cases() as $fieldType) {
            $fields[] = [
                'key' => 'field_' . $fieldType->value,
                'type' => $fieldType->value,
            ];
        }

        $definition = $this->builder->buildFromInput([
            'type' => 'all_fields',
            'label' => 'All Fields',
            'fields' => $fields,
        ]);

        self::assertCount(count(FieldType::cases()), $definition->fields);
    }
}
