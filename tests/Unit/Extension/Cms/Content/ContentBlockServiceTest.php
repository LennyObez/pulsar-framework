<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentBlockService;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(ContentBlockService::class)]
final class ContentBlockServiceTest extends TestCase
{
    private InMemoryBlockRepository $repo;
    private ContentBlockService $service;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBlockRepository();
        $this->service = new ContentBlockService($this->repo);
    }

    #[Test]
    public function addBlockAppendsAtEnd(): void
    {
        $block1 = $this->service->addBlock('c1', 'en', 'text', ['content' => 'A']);
        $block2 = $this->service->addBlock('c1', 'en', 'image', ['media_id' => 'm1']);

        self::assertSame(0, $block1->sortOrder);
        self::assertSame(1, $block2->sortOrder);
        self::assertSame('text', $block1->blockType);
        self::assertSame('image', $block2->blockType);
        self::assertCount(2, $this->repo->findByContentAndLocale('c1', 'en'));
    }

    #[Test]
    public function addBlockAtPositionShiftsExisting(): void
    {
        $this->service->addBlock('c1', 'en', 'text', ['content' => 'A']);
        $this->service->addBlock('c1', 'en', 'text', ['content' => 'B']);
        $inserted = $this->service->addBlock('c1', 'en', 'code', ['code' => '<?php'], position: 1);

        self::assertSame(1, $inserted->sortOrder);
        self::assertSame('code', $inserted->blockType);

        $blocks = $this->repo->findByContentAndLocale('c1', 'en');
        // Block B should have been shifted from sortOrder 1 to 2
        $sortOrders = array_map(static fn(ContentBlock $b) => $b->sortOrder, $blocks);
        sort($sortOrders);
        self::assertSame([0, 1, 2], $sortOrders);
    }

    #[Test]
    public function updateBlockChangesData(): void
    {
        $original = $this->service->addBlock('c1', 'en', 'text', ['content' => 'Old']);
        $updated = $this->service->updateBlock($original->id, ['content' => 'New']);

        self::assertSame($original->id, $updated->id);
        self::assertSame(['content' => 'New'], $updated->data);
        self::assertSame('text', $updated->blockType);
        self::assertSame($original->sortOrder, $updated->sortOrder);
    }

    #[Test]
    public function updateBlockThrowsWhenNotFound(): void
    {
        $this->expectException(CmsException::class);
        $this->service->updateBlock('nonexistent', ['content' => 'x']);
    }

    #[Test]
    public function removeBlockCompactsSortOrder(): void
    {
        $a = $this->service->addBlock('c1', 'en', 'text', ['content' => 'A']);
        $b = $this->service->addBlock('c1', 'en', 'text', ['content' => 'B']);
        $this->service->addBlock('c1', 'en', 'text', ['content' => 'C']);

        $this->service->removeBlock($b->id);

        $remaining = $this->repo->findByContentAndLocale('c1', 'en');
        self::assertCount(2, $remaining);

        $sortOrders = array_map(static fn(ContentBlock $b) => $b->sortOrder, $remaining);
        sort($sortOrders);
        self::assertSame([0, 1], $sortOrders);
    }

    #[Test]
    public function removeBlockThrowsWhenNotFound(): void
    {
        $this->expectException(CmsException::class);
        $this->service->removeBlock('nonexistent');
    }

    #[Test]
    public function reorderBlocksAppliesNewOrder(): void
    {
        $a = $this->service->addBlock('c1', 'en', 'text', ['content' => 'A']);
        $b = $this->service->addBlock('c1', 'en', 'text', ['content' => 'B']);
        $c = $this->service->addBlock('c1', 'en', 'text', ['content' => 'C']);

        // Reverse order: C, A, B
        $this->service->reorderBlocks('c1', 'en', [$c->id, $a->id, $b->id]);

        $blocks = $this->repo->findByContentAndLocale('c1', 'en');
        $orderById = [];
        foreach ($blocks as $block) {
            $orderById[$block->id] = $block->sortOrder;
        }

        self::assertSame(0, $orderById[$c->id]);
        self::assertSame(1, $orderById[$a->id]);
        self::assertSame(2, $orderById[$b->id]);
    }

    #[Test]
    public function reorderBlocksIgnoresUnknownIds(): void
    {
        $a = $this->service->addBlock('c1', 'en', 'text', ['content' => 'A']);

        // Include an unknown ID — should be silently skipped
        $this->service->reorderBlocks('c1', 'en', ['unknown-id', $a->id]);

        $blocks = $this->repo->findByContentAndLocale('c1', 'en');
        self::assertCount(1, $blocks);
        self::assertSame(1, $blocks[0]->sortOrder);
    }

    #[Test]
    public function addBlockToDifferentLocalesAreIsolated(): void
    {
        $this->service->addBlock('c1', 'en', 'text', ['content' => 'EN']);
        $this->service->addBlock('c1', 'fr', 'text', ['content' => 'FR']);

        self::assertCount(1, $this->repo->findByContentAndLocale('c1', 'en'));
        self::assertCount(1, $this->repo->findByContentAndLocale('c1', 'fr'));
    }
}

/**
 * In-memory implementation of ContentBlockRepositoryInterface for testing.
 */
final class InMemoryBlockRepository implements ContentBlockRepositoryInterface
{
    /** @var array<string, ContentBlock> */
    private array $blocks = [];

    public function findById(string $id): ?ContentBlock
    {
        return $this->blocks[$id] ?? null;
    }

    public function findByContentAndLocale(string $contentId, string $locale): array
    {
        $result = [];
        foreach ($this->blocks as $block) {
            if ($block->contentId === $contentId && $block->locale === $locale) {
                $result[] = $block;
            }
        }

        usort($result, static fn(ContentBlock $a, ContentBlock $b) => $a->sortOrder <=> $b->sortOrder);

        return $result;
    }

    public function save(ContentBlock $block): void
    {
        $this->blocks[$block->id] = $block;
    }

    public function saveAll(array $blocks): void
    {
        foreach ($blocks as $block) {
            $this->blocks[$block->id] = $block;
        }
    }

    public function delete(string $id): void
    {
        unset($this->blocks[$id]);
    }

    public function deleteByContentAndLocale(string $contentId, string $locale): void
    {
        foreach ($this->blocks as $id => $block) {
            if ($block->contentId === $contentId && $block->locale === $locale) {
                unset($this->blocks[$id]);
            }
        }
    }
}
