<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\HasManyThroughEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\MorphCommentEntity;

final class MetadataCompilerMorphTest extends TestCase
{
    private MetadataCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MetadataCompiler(OrmConfig::fromArray([]));
    }

    #[Test]
    public function compileMorphToRelationReadsMorphColumns(): void
    {
        $metadata = $this->compiler->compile(MorphCommentEntity::class);

        self::assertArrayHasKey('commentable', $metadata->relations);
        $relation = $metadata->relations['commentable'];

        self::assertSame(RelationType::MorphTo, $relation->type);
        self::assertSame('commentable_type', $relation->morphTypeColumn);
        self::assertSame('commentable_id', $relation->morphIdColumn);
    }

    #[Test]
    public function compileHasManyThroughRelationReadsThroughFields(): void
    {
        $metadata = $this->compiler->compile(HasManyThroughEntity::class);

        self::assertArrayHasKey('posts', $metadata->relations);
        $relation = $metadata->relations['posts'];

        self::assertSame(RelationType::HasManyThrough, $relation->type);
        self::assertSame('country_id', $relation->throughForeignKey);
        self::assertSame('user_id', $relation->throughLocalKey);
        self::assertNotNull($relation->throughEntity);
    }

    #[Test]
    public function compileMorphRelationWithNullColumnsReturnsNull(): void
    {
        // Standard HasMany relation should have null morph columns
        $compiler = new MetadataCompiler(OrmConfig::fromArray([]));
        $metadata = $compiler->compile(\Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity::class);

        $postsRelation = $metadata->relations['posts'];
        self::assertSame(RelationType::HasMany, $postsRelation->type);
        self::assertNull($postsRelation->morphTypeColumn);
        self::assertNull($postsRelation->morphIdColumn);
        self::assertNull($postsRelation->throughEntity);
        self::assertNull($postsRelation->throughForeignKey);
        self::assertNull($postsRelation->throughLocalKey);
    }
}
