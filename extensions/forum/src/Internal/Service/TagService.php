<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Service\TagServiceInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;

use function array_slice;

/**
 * Tag service — CRUD and thread association management.
 */
#[Internal(reason: 'Use TagServiceInterface for public API')]
final readonly class TagService implements TagServiceInterface
{
    public function __construct(
        private TagRepositoryInterface $tags,
    ) {}

    public function createTag(string $name, string $slug, ?string $description = null): Tag
    {
        $tag = Tag::create(
            id: UuidGenerator::v7(),
            slug: $slug,
            name: $name,
            description: $description ?? '',
        );

        $this->tags->save($tag);

        return $tag;
    }

    public function attachTags(string $threadId, array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $tag = $this->tags->findById($tagId);

            if ($tag === null) {
                throw ForumException::notFound('Tag', $tagId);
            }

            $this->tags->attachToThread($tagId, $threadId);

            // Increment usage count
            $tag = $tag->incrementUsage();
            $this->tags->save($tag);
        }
    }

    public function detachTags(string $threadId, array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $tag = $this->tags->findById($tagId);

            if ($tag === null) {
                throw ForumException::notFound('Tag', $tagId);
            }

            $this->tags->detachFromThread($tagId, $threadId);

            // Decrement usage count
            $tag = $tag->decrementUsage();
            $this->tags->save($tag);
        }
    }

    public function findPopular(int $limit = 20): array
    {
        $all = $this->tags->findAll();

        return array_slice($all, 0, $limit);
    }
}
