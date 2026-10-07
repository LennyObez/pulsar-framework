<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Execution\GraphqlException;
use Pulsar\Extension\Graphql\Execution\ParsedField;
use Pulsar\Extension\Graphql\Schema\FieldDefinition;
use Pulsar\Extension\Graphql\Schema\ObjectType;
use Pulsar\Extension\Graphql\Schema\Schema;

#[CoversClass(Schema::class)]
#[CoversClass(ParsedField::class)]
#[CoversClass(GraphqlException::class)]
final class SchemaIntrospectionTest extends TestCase
{
    #[Test]
    public function getTypeReturnsObjectType(): void
    {
        $queryType = new ObjectType('Query', []);
        $articleType = new ObjectType('Article', []);
        $schema = new Schema($queryType, ['Query' => $queryType, 'Article' => $articleType]);

        $result = $schema->getType('Article');

        self::assertNotNull($result);
        self::assertSame('Article', $result->name);
    }

    #[Test]
    public function getTypeReturnsNullForUnknown(): void
    {
        $queryType = new ObjectType('Query', []);
        $schema = new Schema($queryType, ['Query' => $queryType]);

        self::assertNull($schema->getType('NonExistent'));
    }

    #[Test]
    public function toIntrospectionIncludesSchemaAndScalars(): void
    {
        $queryType = new ObjectType('Query', [
            'hello' => new FieldDefinition('hello', 'String'),
        ]);
        $schema = new Schema($queryType, ['Query' => $queryType]);

        $intro = $schema->toIntrospection();

        self::assertArrayHasKey('__schema', $intro);

        /** @var array{queryType: array{name: string}, mutationType: mixed, subscriptionType: mixed, types: list<array{name: string, ...}>} $schemaData */
        $schemaData = $intro['__schema'];

        self::assertSame('Query', $schemaData['queryType']['name']);
        self::assertNull($schemaData['mutationType']);
        self::assertNull($schemaData['subscriptionType']);

        // Should include Query type + 5 scalars = 6 total
        self::assertCount(6, $schemaData['types']);

        // Verify scalar types are present
        $typeNames = array_column($schemaData['types'], 'name');
        self::assertContains('String', $typeNames);
        self::assertContains('Int', $typeNames);
        self::assertContains('Float', $typeNames);
        self::assertContains('Boolean', $typeNames);
        self::assertContains('ID', $typeNames);
    }

    // --- ParsedField ---

    #[Test]
    public function parsedFieldResponseKeyUsesAlias(): void
    {
        $field = new ParsedField(name: 'title', alias: 'myTitle');

        self::assertSame('myTitle', $field->responseKey());
    }

    #[Test]
    public function parsedFieldResponseKeyUsesNameWithoutAlias(): void
    {
        $field = new ParsedField(name: 'title');

        self::assertSame('title', $field->responseKey());
    }

    #[Test]
    public function parsedFieldWithArguments(): void
    {
        $field = new ParsedField(
            name: 'article',
            arguments: ['id' => 42, 'slug' => 'test'],
        );

        self::assertSame(42, $field->arguments['id']);
        self::assertSame('test', $field->arguments['slug']);
    }

    #[Test]
    public function parsedFieldWithSelections(): void
    {
        $sub = new ParsedField(name: 'author');
        $field = new ParsedField(name: 'article', selections: [$sub]);

        self::assertCount(1, $field->selections);
        self::assertSame('author', $field->selections[0]->name);
    }

    // --- GraphqlException ---

    #[Test]
    public function syntaxErrorFactory(): void
    {
        $e = GraphqlException::syntaxError('unexpected token');

        self::assertStringContainsString('syntax error', $e->getMessage());
        self::assertStringContainsString('unexpected token', $e->getMessage());
    }

    #[Test]
    public function validationErrorFactory(): void
    {
        $e = GraphqlException::validationError('unknown field');

        self::assertStringContainsString('validation error', $e->getMessage());
        self::assertStringContainsString('unknown field', $e->getMessage());
    }

    #[Test]
    public function executionErrorFactory(): void
    {
        $e = GraphqlException::executionError('resolver failed');

        self::assertStringContainsString('execution error', $e->getMessage());
        self::assertStringContainsString('resolver failed', $e->getMessage());
    }
}
