<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Fixtures;

/**
 * Polymorphic comment entity for testing MorphTo/MorphMany relations.
 *
 * A comment can belong to any "commentable" entity (Post, UserEntity, etc.)
 * via the commentable_type and commentable_id columns.
 */
final class CommentEntity
{
    public function __construct(
        public readonly int $id = 0,
        public readonly string $body = '',
        public readonly string $commentable_type = '',
        public readonly int $commentable_id = 0,
        public ?object $commentable = null,
    ) {}
}
