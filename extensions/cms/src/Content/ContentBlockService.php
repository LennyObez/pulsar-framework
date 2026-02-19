<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;

use function count;
use function sprintf;

/**
 * Manages content block CRUD operations and ordering.
 *
 * Content blocks are reusable components (text, image, gallery, etc.)
 * that compose a content item's body within a specific locale.
 */
#[Internal]
final readonly class ContentBlockService
{
    public function __construct(
        private ContentBlockRepositoryInterface $blockRepository,
    ) {}

    /**
     * Add a new block to a content item at an optional position.
     *
     * @param string $contentId UUIDv7
     * @param string $locale BCP 47 locale code
     * @param string $blockType Block type identifier
     * @param array<string, mixed> $data Block-type-specific payload
     * @param int|null $position Insertion position; null = append at end
     */
    public function addBlock(
        string $contentId,
        string $locale,
        string $blockType,
        array $data,
        ?int $position = null,
    ): ContentBlock {
        $existingBlocks = $this->blockRepository->findByContentAndLocale($contentId, $locale);

        if ($position === null) {
            $sortOrder = count($existingBlocks);
        } else {
            $sortOrder = $position;

            // Shift existing blocks at or after the insertion point
            foreach ($existingBlocks as $block) {
                if ($block->sortOrder >= $position) {
                    $shifted = new ContentBlock(
                        id: $block->id,
                        contentId: $block->contentId,
                        locale: $block->locale,
                        blockType: $block->blockType,
                        sortOrder: $block->sortOrder + 1,
                        data: $block->data,
                        createdAt: $block->createdAt,
                        updatedAt: new DateTimeImmutable(),
                    );
                    $this->blockRepository->save($shifted);
                }
            }
        }

        $now = new DateTimeImmutable();
        $block = new ContentBlock(
            id: $this->generateId(),
            contentId: $contentId,
            locale: $locale,
            blockType: $blockType,
            sortOrder: $sortOrder,
            data: $data,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->blockRepository->save($block);

        return $block;
    }

    /**
     * Update the data payload of an existing block.
     *
     * @param string $blockId UUIDv7
     * @param array<string, mixed> $data New block-type-specific payload
     *
     * @throws CmsException If the block is not found
     */
    public function updateBlock(string $blockId, array $data): ContentBlock
    {
        $block = $this->blockRepository->findById($blockId);

        if ($block === null) {
            throw CmsException::contentNotFound($blockId);
        }

        $updated = new ContentBlock(
            id: $block->id,
            contentId: $block->contentId,
            locale: $block->locale,
            blockType: $block->blockType,
            sortOrder: $block->sortOrder,
            data: $data,
            createdAt: $block->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        $this->blockRepository->save($updated);

        return $updated;
    }

    /**
     * Remove a block and compact the sort order of remaining blocks.
     *
     * @param string $blockId UUIDv7
     *
     * @throws CmsException If the block is not found
     */
    public function removeBlock(string $blockId): void
    {
        $block = $this->blockRepository->findById($blockId);

        if ($block === null) {
            throw CmsException::contentNotFound($blockId);
        }

        $this->blockRepository->delete($blockId);

        // Compact sort order for remaining blocks
        $remainingBlocks = $this->blockRepository->findByContentAndLocale($block->contentId, $block->locale);

        foreach ($remainingBlocks as $index => $remaining) {
            if ($remaining->sortOrder !== $index) {
                $reordered = new ContentBlock(
                    id: $remaining->id,
                    contentId: $remaining->contentId,
                    locale: $remaining->locale,
                    blockType: $remaining->blockType,
                    sortOrder: $index,
                    data: $remaining->data,
                    createdAt: $remaining->createdAt,
                    updatedAt: new DateTimeImmutable(),
                );
                $this->blockRepository->save($reordered);
            }
        }
    }

    /**
     * Reorder blocks according to the provided ordered list of block IDs.
     *
     * @param string $contentId UUIDv7
     * @param string $locale BCP 47 locale code
     * @param list<string> $orderedIds Block IDs in the desired order
     */
    public function reorderBlocks(string $contentId, string $locale, array $orderedIds): void
    {
        $blocks = $this->blockRepository->findByContentAndLocale($contentId, $locale);

        // Index blocks by ID for lookup
        $blockMap = [];
        foreach ($blocks as $block) {
            $blockMap[$block->id] = $block;
        }

        foreach ($orderedIds as $position => $blockId) {
            if (!isset($blockMap[$blockId])) {
                continue;
            }

            $block = $blockMap[$blockId];

            if ($block->sortOrder !== $position) {
                $reordered = new ContentBlock(
                    id: $block->id,
                    contentId: $block->contentId,
                    locale: $block->locale,
                    blockType: $block->blockType,
                    sortOrder: $position,
                    data: $block->data,
                    createdAt: $block->createdAt,
                    updatedAt: new DateTimeImmutable(),
                );
                $this->blockRepository->save($reordered);
            }
        }
    }

    private function generateId(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12) . bin2hex(random_bytes(1)),
        );
    }
}
