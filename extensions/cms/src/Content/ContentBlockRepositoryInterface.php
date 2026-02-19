<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Repository interface for content blocks.
 */
#[Api(since: '1.0.0')]
interface ContentBlockRepositoryInterface
{
    public function findById(string $id): ?ContentBlock;

    /**
     * Find all blocks for a content item in a locale, ordered by sort_order.
     *
     * @return list<ContentBlock>
     */
    public function findByContentAndLocale(string $contentId, string $locale): array;

    public function save(ContentBlock $block): void;

    /**
     * Save multiple blocks in a single transaction.
     *
     * @param list<ContentBlock> $blocks
     */
    public function saveAll(array $blocks): void;

    public function delete(string $id): void;

    /**
     * Delete all blocks for a content item in a locale.
     */
    public function deleteByContentAndLocale(string $contentId, string $locale): void;
}
