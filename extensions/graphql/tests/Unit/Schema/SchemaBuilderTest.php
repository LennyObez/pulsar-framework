<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Schema\SchemaBuilder;

final class SchemaBuilderTest extends TestCase
{
    #[Test]
    public function buildCreatesSchemaWithQueryType(): void
    {
        $builder = new SchemaBuilder();
        $schema = $builder->build();

        self::assertSame('Query', $schema->queryType->name);
    }

    #[Test]
    public function buildIncludesContentType(): void
    {
        $schema = new SchemaBuilder()->build();

        $contentType = $schema->getType('Content');

        self::assertNotNull($contentType);
        self::assertArrayHasKey('id', $contentType->fields);
        self::assertArrayHasKey('contentType', $contentType->fields);
        self::assertArrayHasKey('translations', $contentType->fields);
        self::assertArrayHasKey('blocks', $contentType->fields);
    }

    #[Test]
    public function buildIncludesMediaType(): void
    {
        $schema = new SchemaBuilder()->build();

        $mediaType = $schema->getType('Media');

        self::assertNotNull($mediaType);
        self::assertArrayHasKey('filename', $mediaType->fields);
        self::assertArrayHasKey('mimeType', $mediaType->fields);
    }

    #[Test]
    public function buildIncludesTaxonomyType(): void
    {
        $schema = new SchemaBuilder()->build();

        $taxonomy = $schema->getType('Taxonomy');

        self::assertNotNull($taxonomy);
        self::assertArrayHasKey('slug', $taxonomy->fields);
        self::assertArrayHasKey('terms', $taxonomy->fields);
    }

    #[Test]
    public function buildQueryTypeHasAllRootFields(): void
    {
        $schema = new SchemaBuilder()->build();

        self::assertArrayHasKey('content', $schema->queryType->fields);
        self::assertArrayHasKey('contents', $schema->queryType->fields);
        self::assertArrayHasKey('taxonomy', $schema->queryType->fields);
        self::assertArrayHasKey('media', $schema->queryType->fields);
    }

    #[Test]
    public function buildIncludesContentConnectionType(): void
    {
        $schema = new SchemaBuilder()->build();

        $connection = $schema->getType('ContentConnection');

        self::assertNotNull($connection);
        self::assertArrayHasKey('items', $connection->fields);
        self::assertArrayHasKey('totalCount', $connection->fields);
        self::assertArrayHasKey('page', $connection->fields);
    }

    #[Test]
    public function buildIncludesTranslationAndBlockTypes(): void
    {
        $schema = new SchemaBuilder()->build();

        self::assertNotNull($schema->getType('Translation'));
        self::assertNotNull($schema->getType('ContentBlock'));
        self::assertNotNull($schema->getType('TaxonomyTerm'));
    }
}
