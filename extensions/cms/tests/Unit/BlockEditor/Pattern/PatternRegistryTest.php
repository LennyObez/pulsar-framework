<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\Pattern;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\Pattern\BlockPattern;
use Pulsar\Extension\Cms\BlockEditor\Pattern\CorePatterns;
use Pulsar\Extension\Cms\BlockEditor\Pattern\PatternRegistry;

#[CoversClass(PatternRegistry::class)]
#[CoversClass(BlockPattern::class)]
#[CoversClass(CorePatterns::class)]
final class PatternRegistryTest extends TestCase
{
    private PatternRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PatternRegistry();
    }

    public function testRegisterAndGet(): void
    {
        $pattern = new BlockPattern(
            name: 'test-pattern',
            title: 'Test Pattern',
            description: 'A test pattern',
            category: 'test',
            blocks: [['type' => 'paragraph', 'data' => ['text' => 'Hello']]],
        );

        $this->registry->register($pattern);

        self::assertSame($pattern, $this->registry->get('test-pattern'));
    }

    public function testGetReturnsNullForUnknown(): void
    {
        self::assertNull($this->registry->get('nonexistent'));
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $this->registry->register(new BlockPattern('p', 'P', '', 'cat', []));

        self::assertTrue($this->registry->has('p'));
        self::assertFalse($this->registry->has('unknown'));
    }

    public function testByCategoryFilters(): void
    {
        $this->registry->register(new BlockPattern('a', 'A', '', 'landing', []));
        $this->registry->register(new BlockPattern('b', 'B', '', 'content', []));
        $this->registry->register(new BlockPattern('c', 'C', '', 'landing', []));

        $landing = $this->registry->byCategory('landing');

        self::assertCount(2, $landing);
        self::assertSame('a', $landing[0]->name);
        self::assertSame('c', $landing[1]->name);
    }

    public function testSearchByTitle(): void
    {
        $this->registry->register(new BlockPattern('hero', 'Hero Section', '', 'landing', []));
        $this->registry->register(new BlockPattern('footer', 'Footer', '', 'layout', []));

        $results = $this->registry->search('hero');

        self::assertCount(1, $results);
        self::assertSame('hero', $results[0]->name);
    }

    public function testSearchByKeyword(): void
    {
        $this->registry->register(new BlockPattern('p1', 'Title', '', 'cat', [], ['banner', 'splash']));

        $results = $this->registry->search('banner');

        self::assertCount(1, $results);
    }

    public function testSearchByDescription(): void
    {
        $this->registry->register(new BlockPattern('p1', 'Title', 'Contains pricing info', 'cat', []));

        $results = $this->registry->search('pricing');

        self::assertCount(1, $results);
    }

    public function testAllReturnsAllPatterns(): void
    {
        $this->registry->register(new BlockPattern('a', 'A', '', 'cat', []));
        $this->registry->register(new BlockPattern('b', 'B', '', 'cat', []));

        self::assertCount(2, $this->registry->all());
    }

    public function testCategoriesReturnsUniqueCategories(): void
    {
        $this->registry->register(new BlockPattern('a', 'A', '', 'landing', []));
        $this->registry->register(new BlockPattern('b', 'B', '', 'content', []));
        $this->registry->register(new BlockPattern('c', 'C', '', 'landing', []));

        $categories = $this->registry->categories();

        self::assertCount(2, $categories);
        self::assertContains('landing', $categories);
        self::assertContains('content', $categories);
    }

    public function testCorePatternRegistration(): void
    {
        CorePatterns::register($this->registry);

        self::assertTrue($this->registry->has('hero-cta'));
        self::assertTrue($this->registry->has('feature-grid'));
        self::assertTrue($this->registry->has('pricing-page'));
        self::assertTrue($this->registry->has('contact-section'));
        self::assertTrue($this->registry->has('hero-cta-testimonials'));
    }

    public function testCorePatternsHaveBlocks(): void
    {
        CorePatterns::register($this->registry);

        foreach ($this->registry->all() as $pattern) {
            self::assertNotEmpty($pattern->blocks, "Pattern '{$pattern->name}' has no blocks");
        }
    }
}
