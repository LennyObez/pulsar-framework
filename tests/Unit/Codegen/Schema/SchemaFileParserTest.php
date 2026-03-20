<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\SchemaFileParser;
use RuntimeException;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SchemaFileParser::class)]
final class SchemaFileParserTest extends TestCase
{
    #[Test]
    public function parses_minimal_schema(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'User' => [
                    'table' => 'users',
                    'properties' => [
                        'name' => ['type' => 'string', 'length' => 255],
                        'email' => ['type' => 'string'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, $entities);
        self::assertSame('User', $entities[0]->className);
        self::assertSame('users', $entities[0]->tableName);
        self::assertCount(2, $entities[0]->properties);
    }

    #[Test]
    public function parses_entity_with_relations(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'Post' => [
                    'table' => 'posts',
                    'properties' => [
                        'title' => ['type' => 'string'],
                    ],
                    'relations' => [
                        'comments' => ['type' => 'hasMany', 'target' => 'Comment'],
                        'author' => ['type' => 'belongsTo', 'target' => 'User'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(2, $entities[0]->relationships);
    }

    #[Test]
    public function parses_timestamps_and_soft_deletes(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'Article' => [
                    'table' => 'articles',
                    'properties' => [],
                    'timestamps' => true,
                    'softDeletes' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($entities[0]->hasTimestamps);
        self::assertTrue($entities[0]->hasSoftDeletes);
    }

    #[Test]
    public function parses_custom_namespace(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'namespace' => 'Custom\\Models',
            'entities' => [
                'Order' => ['table' => 'orders', 'properties' => []],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('Custom\\Models', $entities[0]->namespace);
    }

    #[Test]
    public function default_namespace(): void
    {
        $parser = new SchemaFileParser('My\\Namespace');

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'Item' => ['table' => 'items', 'properties' => []],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('My\\Namespace', $entities[0]->namespace);
    }

    #[Test]
    public function maps_schema_types_to_php(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'Mixed' => [
                    'table' => 'mixed',
                    'properties' => [
                        'count' => ['type' => 'integer'],
                        'amount' => ['type' => 'decimal'],
                        'active' => ['type' => 'boolean'],
                        'data' => ['type' => 'json'],
                        'created' => ['type' => 'datetime'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $props = $entities[0]->properties;
        self::assertSame('int', $props[0]->phpType);
        self::assertSame('float', $props[1]->phpType);
        self::assertSame('bool', $props[2]->phpType);
        self::assertSame('array', $props[3]->phpType);
        self::assertSame('DateTimeImmutable', $props[4]->phpType);
    }

    #[Test]
    public function parses_nullable_properties(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'Profile' => [
                    'table' => 'profiles',
                    'properties' => [
                        'bio' => ['type' => 'text', 'nullable' => true],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($entities[0]->properties[0]->nullable);
    }

    #[Test]
    public function parses_multiple_entities(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'User' => ['table' => 'users', 'properties' => []],
                'Post' => ['table' => 'posts', 'properties' => []],
                'Comment' => ['table' => 'comments', 'properties' => []],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(3, $entities);
    }

    #[Test]
    public function ignores_invalid_entity_definitions(): void
    {
        $parser = new SchemaFileParser();

        $entities = $parser->parseJson(json_encode([
            'entities' => [
                'Valid' => ['table' => 'valid', 'properties' => []],
                'Invalid' => 'not-an-array',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, $entities);
    }

    /**
     * F387.8: schema files outside the `.pulsar.json` extension
     * are rejected before any disk read happens.
     */
    #[Test]
    public function rejectsNonPulsarJsonExtension(): void
    {
        $parser = new SchemaFileParser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only .pulsar.json files are accepted');

        (void) $parser->parseFile(__DIR__ . '/fixture.json');
    }

    /**
     * F387.8: when an `allowedRoot` is configured, schema paths
     * that resolve outside that root are rejected.
     */
    #[Test]
    public function rejectsPathOutsideAllowedRoot(): void
    {
        $tempFile = sys_get_temp_dir() . '/pulsar-codegen-rejected.pulsar.json';
        file_put_contents($tempFile, '{"entities":{}}');

        try {
            $parser = new SchemaFileParser(
                defaultNamespace: 'App\\Entity',
                allowedRoot: __DIR__,
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('outside the allowed root');

            (void) $parser->parseFile($tempFile);
        } finally {
            new \Symfony\Component\Filesystem\Filesystem()->remove($tempFile);
        }
    }

    /**
     * F387.8: paths with `.pulsar.json` suffix and inside the
     * allowed root parse normally.
     */
    #[Test]
    public function acceptsPulsarJsonInsideAllowedRoot(): void
    {
        $dir = sys_get_temp_dir() . '/pulsar-codegen-accept';
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->mkdir($dir, 0o775);
        $file = $dir . '/sample.pulsar.json';
        file_put_contents($file, json_encode([
            'entities' => [
                'Sample' => ['table' => 'samples', 'properties' => []],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $parser = new SchemaFileParser(
                defaultNamespace: 'App\\Entity',
                allowedRoot: $dir,
            );

            $entities = $parser->parseFile($file);

            self::assertCount(1, $entities);
            self::assertSame('Sample', $entities[0]->className);
        } finally {
            $fs->remove($dir);
        }
    }
}
