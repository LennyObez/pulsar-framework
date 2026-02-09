<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Schema\SchemaBuilder;

#[CoversClass(SchemaBuilder::class)]
final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SchemaBuilder();
    }

    #[Test]
    public function build_returns_schema_with_query_root(): void
    {
        $schema = $this->builder->build();

        self::assertSame('Query', $schema->queryType->name);
    }

    #[Test]
    public function schema_contains_all_expected_types(): void
    {
        $schema = $this->builder->build();
        $expectedTypes = ['Query', 'Content', 'Translation', 'ContentBlock', 'Taxonomy', 'TaxonomyTerm', 'Media', 'ContentConnection'];

        foreach ($expectedTypes as $typeName) {
            self::assertNotNull($schema->getType($typeName), "Missing type: {$typeName}");
        }
    }

    #[Test]
    public function query_type_has_expected_root_fields(): void
    {
        $schema = $this->builder->build();
        $query = $schema->queryType;

        self::assertArrayHasKey('content', $query->fields);
        self::assertArrayHasKey('contents', $query->fields);
        self::assertArrayHasKey('taxonomy', $query->fields);
        self::assertArrayHasKey('media', $query->fields);
    }

    #[Test]
    public function content_type_has_expected_fields(): void
    {
        $schema = $this->builder->build();
        $content = $schema->getType('Content');
        self::assertNotNull($content);

        $expectedFields = ['id', 'tenantId', 'contentType', 'authorId', 'status', 'template', 'parentId', 'sortOrder', 'commentPolicy', 'publishedAt', 'createdAt', 'updatedAt', 'translations', 'blocks'];

        foreach ($expectedFields as $fieldName) {
            self::assertArrayHasKey($fieldName, $content->fields, "Missing field: {$fieldName}");
        }
    }

    #[Test]
    public function content_id_field_is_non_null_id(): void
    {
        $schema = $this->builder->build();
        $content = $schema->getType('Content');
        self::assertNotNull($content);

        $idField = $content->fields['id'];
        self::assertSame('ID', $idField->type);
        self::assertTrue($idField->nonNull);
    }

    #[Test]
    public function content_connection_type_has_pagination_fields(): void
    {
        $schema = $this->builder->build();
        $connection = $schema->getType('ContentConnection');
        self::assertNotNull($connection);

        self::assertArrayHasKey('items', $connection->fields);
        self::assertArrayHasKey('totalCount', $connection->fields);
        self::assertArrayHasKey('page', $connection->fields);
        self::assertArrayHasKey('perPage', $connection->fields);

        self::assertTrue($connection->fields['items']->isList);
        self::assertTrue($connection->fields['items']->listItemNonNull);
    }

    #[Test]
    public function taxonomy_type_has_terms_field_with_locale_argument(): void
    {
        $schema = $this->builder->build();
        $taxonomy = $schema->getType('Taxonomy');
        self::assertNotNull($taxonomy);

        $termsField = $taxonomy->fields['terms'];
        self::assertTrue($termsField->isList);
        self::assertArrayHasKey('locale', $termsField->arguments);
        self::assertTrue($termsField->arguments['locale']->nonNull);
    }

    #[Test]
    public function media_type_has_expected_fields(): void
    {
        $schema = $this->builder->build();
        $media = $schema->getType('Media');
        self::assertNotNull($media);

        $expectedFields = ['id', 'tenantId', 'filename', 'mimeType', 'fileSize', 'width', 'height', 'altTextDefault', 'visibility', 'createdAt'];

        foreach ($expectedFields as $fieldName) {
            self::assertArrayHasKey($fieldName, $media->fields, "Missing field: {$fieldName}");
        }
    }

    #[Test]
    public function schema_introspection_contains_query_type(): void
    {
        $schema = $this->builder->build();
        $introspection = $schema->toIntrospection();

        self::assertArrayHasKey('__schema', $introspection);
        self::assertIsArray($introspection['__schema']);
        self::assertIsArray($introspection['__schema']['queryType']);
        self::assertSame('Query', $introspection['__schema']['queryType']['name']);
        self::assertNull($introspection['__schema']['mutationType']);
        self::assertNull($introspection['__schema']['subscriptionType']);
    }

    #[Test]
    public function contents_field_has_expected_arguments(): void
    {
        $schema = $this->builder->build();
        $query = $schema->queryType;
        $contentsField = $query->fields['contents'];

        self::assertArrayHasKey('locale', $contentsField->arguments);
        self::assertArrayHasKey('type', $contentsField->arguments);
        self::assertArrayHasKey('page', $contentsField->arguments);
        self::assertArrayHasKey('perPage', $contentsField->arguments);
        self::assertTrue($contentsField->arguments['locale']->nonNull);
        self::assertFalse($contentsField->arguments['type']->nonNull);
    }
}
