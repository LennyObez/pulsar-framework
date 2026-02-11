<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Schema\SchemaSnapshot;

use function strlen;

#[CoversClass(SchemaSnapshot::class)]
final class SchemaSnapshotTest extends TestCase
{
    #[Test]
    public function constructWithEntities(): void
    {
        $entity = $this->makeEntity('users', 'User');
        $snapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        self::assertCount(1, $snapshot->entities);
        self::assertArrayHasKey('users', $snapshot->entities);
        self::assertSame('1', $snapshot->version);
    }

    #[Test]
    public function toArrayProducesDeterministicOutput(): void
    {
        $users = $this->makeEntity('users', 'User');
        $posts = $this->makeEntity('posts', 'Post');

        // Even if entities are provided in reverse alphabetical order
        $snapshot = new SchemaSnapshot(
            entities: ['users' => $users, 'posts' => $posts],
            version: '2',
        );

        $array = $snapshot->toArray();

        self::assertSame('2', $array['version']);
        self::assertArrayHasKey('entities', $array);

        // Keys should be sorted
        $entityKeys = array_keys($array['entities']);
        self::assertSame(['posts', 'users'], $entityKeys);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $entity = $this->makeEntity('users', 'User');
        $snapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '3',
        );

        $restored = SchemaSnapshot::fromArray($snapshot->toArray());

        self::assertSame($snapshot->version, $restored->version);
        self::assertCount(1, $restored->entities);
        self::assertArrayHasKey('users', $restored->entities);
        self::assertSame('User', $restored->entities['users']->className);
    }

    #[Test]
    public function hashIsDeterministic(): void
    {
        $entity = $this->makeEntity('users', 'User');
        $snapshot1 = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );
        $snapshot2 = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        self::assertSame($snapshot1->hash(), $snapshot2->hash());
    }

    #[Test]
    public function hashChangesWhenContentChanges(): void
    {
        $entity1 = $this->makeEntity('users', 'User');
        $entity2 = $this->makeEntity('users', 'UserUpdated');

        $snapshot1 = new SchemaSnapshot(
            entities: ['users' => $entity1],
            version: '1',
        );
        $snapshot2 = new SchemaSnapshot(
            entities: ['users' => $entity2],
            version: '1',
        );

        self::assertNotSame($snapshot1->hash(), $snapshot2->hash());
    }

    #[Test]
    public function hashIsIndependentOfEntityInsertionOrder(): void
    {
        $users = $this->makeEntity('users', 'User');
        $posts = $this->makeEntity('posts', 'Post');

        $snapshot1 = new SchemaSnapshot(
            entities: ['users' => $users, 'posts' => $posts],
            version: '1',
        );
        $snapshot2 = new SchemaSnapshot(
            entities: ['posts' => $posts, 'users' => $users],
            version: '1',
        );

        self::assertSame($snapshot1->hash(), $snapshot2->hash());
    }

    #[Test]
    public function entityNamesReturnsSortedList(): void
    {
        $snapshot = new SchemaSnapshot(
            entities: [
                'users' => $this->makeEntity('users', 'User'),
                'comments' => $this->makeEntity('comments', 'Comment'),
                'posts' => $this->makeEntity('posts', 'Post'),
            ],
            version: '1',
        );

        self::assertSame(['comments', 'posts', 'users'], $snapshot->entityNames());
    }

    #[Test]
    public function emptySnapshotProducesValidHash(): void
    {
        $snapshot = new SchemaSnapshot(entities: [], version: '1');

        $hash = $snapshot->hash();

        self::assertSame(64, strlen($hash)); // SHA-256 hex
    }

    #[Test]
    public function fromArrayWithMissingDataUsesDefaults(): void
    {
        $snapshot = SchemaSnapshot::fromArray([]);

        self::assertSame([], $snapshot->entities);
        self::assertSame('1', $snapshot->version);
    }

    private function makeEntity(string $tableName, string $className): EntityDefinition
    {
        return new EntityDefinition(
            className: $className,
            namespace: 'App\\Entity',
            tableName: $tableName,
            properties: [
                new PropertyDefinition(
                    name: 'id',
                    phpType: 'int',
                    columnName: 'id',
                    columnType: 'bigint',
                    nullable: false,
                    hasDefault: true,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: false,
                    isSortable: true,
                    length: null,
                    isPrimaryKey: true,
                ),
            ],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }
}
