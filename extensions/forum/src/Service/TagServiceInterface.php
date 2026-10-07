<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Tag\Tag;

/**
 * Tag service: CRUD and thread association for forum tags.
 * @api
 */
#[Api(since: '1.0.0')]
interface TagServiceInterface
{
    public function createTag(string $name, string $slug, ?string $description = null): Tag;

    /**
     * @param list<string> $tagIds
     */
    public function attachTags(string $threadId, array $tagIds): void;

    /**
     * @param list<string> $tagIds
     */
    public function detachTags(string $threadId, array $tagIds): void;

    /**
     * @return list<Tag>
     */
    public function findPopular(int $limit = 20): array;
}
