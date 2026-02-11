<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;

use function count;
use function sprintf;

final class ManualChecklistGeneratorTest extends TestCase
{
    private ManualChecklistGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ManualChecklistGenerator();
    }

    #[Test]
    public function generate_returns_non_empty_list(): void
    {
        $items = $this->generator->generate();

        self::assertNotEmpty($items);
    }

    #[Test]
    public function all_items_have_wcag_criterion_references(): void
    {
        $items = $this->generator->generate();

        foreach ($items as $item) {
            self::assertNotEmpty($item->wcagCriterion, sprintf(
                'Checklist item "%s" is missing a WCAG criterion reference.',
                $item->id,
            ));
            self::assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+$/',
                $item->wcagCriterion,
                sprintf('Item "%s" has invalid WCAG criterion format: %s', $item->id, $item->wcagCriterion),
            );
        }
    }

    #[Test]
    public function generate_grouped_returns_categorized_items(): void
    {
        $grouped = $this->generator->generateGrouped();

        self::assertNotEmpty($grouped);
        self::assertIsArray($grouped);

        foreach ($grouped as $category => $items) {
            self::assertIsString($category);
            self::assertNotEmpty($items);

            foreach ($items as $item) {
                self::assertSame($category, $item->category);
            }
        }
    }

    #[Test]
    public function all_items_have_required_fields(): void
    {
        $items = $this->generator->generate();

        foreach ($items as $item) {
            self::assertNotEmpty($item->id);
            self::assertNotEmpty($item->category);
            self::assertNotEmpty($item->description);
            self::assertNotEmpty($item->wcagLevel);
            self::assertNotEmpty($item->guidance);
        }
    }

    #[Test]
    public function to_array_returns_correct_structure(): void
    {
        $items = $this->generator->generate();
        $first = $items[0];

        $array = $first->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('category', $array);
        self::assertArrayHasKey('description', $array);
        self::assertArrayHasKey('wcag_criterion', $array);
        self::assertArrayHasKey('wcag_level', $array);
        self::assertArrayHasKey('guidance', $array);
    }

    #[Test]
    public function items_have_unique_ids(): void
    {
        $items = $this->generator->generate();
        $ids = array_map(static fn($item) => $item->id, $items);

        self::assertSame(count($ids), count(array_unique($ids)), 'Checklist items must have unique IDs.');
    }
}
