<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Schema\FieldDefinition;
use Pulsar\Extension\Graphql\Schema\ObjectType;
use Pulsar\Extension\Graphql\Schema\Schema;

final class SchemaTest extends TestCase
{
    private function buildSchema(): Schema
    {
        $queryType = new ObjectType('Query', [
            'content' => new FieldDefinition('content', 'Content'),
        ]);
        $contentType = new ObjectType('Content', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
        ]);

        return new Schema($queryType, [
            'Query' => $queryType,
            'Content' => $contentType,
        ]);
    }

    #[Test]
    public function getTypeReturnsExistingType(): void
    {
        $schema = $this->buildSchema();

        $type = $schema->getType('Content');

        self::assertNotNull($type);
        self::assertSame('Content', $type->name);
    }

    #[Test]
    public function getTypeReturnsNullForUnknown(): void
    {
        $schema = $this->buildSchema();

        self::assertNull($schema->getType('NonExistent'));
    }

    #[Test]
    public function toIntrospectionIncludesQueryType(): void
    {
        $schema = $this->buildSchema();

        $intro = $schema->toIntrospection();

        self::assertSame('Query', $intro['__schema']['queryType']['name']);
        self::assertNull($intro['__schema']['mutationType']);
        self::assertNull($intro['__schema']['subscriptionType']);
    }

    #[Test]
    public function toIntrospectionIncludesScalarTypes(): void
    {
        $schema = $this->buildSchema();

        $intro = $schema->toIntrospection();
        $typeNames = array_column($intro['__schema']['types'], 'name');

        self::assertContains('String', $typeNames);
        self::assertContains('Int', $typeNames);
        self::assertContains('Float', $typeNames);
        self::assertContains('Boolean', $typeNames);
        self::assertContains('ID', $typeNames);
    }

    #[Test]
    public function toIntrospectionIncludesCustomTypes(): void
    {
        $schema = $this->buildSchema();

        $intro = $schema->toIntrospection();
        $typeNames = array_column($intro['__schema']['types'], 'name');

        self::assertContains('Query', $typeNames);
        self::assertContains('Content', $typeNames);
    }
}
