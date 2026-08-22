<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\ChecklistItem;

final class ChecklistItemTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $item = new ChecklistItem(
            id: 'keyboard-1',
            category: 'Keyboard',
            description: 'All interactive elements reachable via Tab',
            wcagCriterion: '2.1.1',
            wcagLevel: 'A',
            guidance: 'Press Tab through the entire page',
        );

        self::assertSame('keyboard-1', $item->id);
        self::assertSame('Keyboard', $item->category);
        self::assertSame('All interactive elements reachable via Tab', $item->description);
        self::assertSame('2.1.1', $item->wcagCriterion);
        self::assertSame('A', $item->wcagLevel);
        self::assertSame('Press Tab through the entire page', $item->guidance);
    }

    #[Test]
    public function toArrayReturnsExpectedKeys(): void
    {
        $item = new ChecklistItem(
            id: 'focus-1',
            category: 'Focus',
            description: 'Focus indicator is visible',
            wcagCriterion: '2.4.7',
            wcagLevel: 'AA',
            guidance: 'Check focus outlines',
        );

        $array = $item->toArray();

        self::assertSame('focus-1', $array['id']);
        self::assertSame('Focus', $array['category']);
        self::assertSame('Focus indicator is visible', $array['description']);
        self::assertSame('2.4.7', $array['wcag_criterion']);
        self::assertSame('AA', $array['wcag_level']);
        self::assertSame('Check focus outlines', $array['guidance']);
        self::assertCount(6, $array);
    }
}
