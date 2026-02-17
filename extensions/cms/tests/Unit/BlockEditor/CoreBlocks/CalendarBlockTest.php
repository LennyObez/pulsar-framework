<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CalendarBlock;

#[CoversClass(CalendarBlock::class)]
final class CalendarBlockTest extends TestCase
{
    private CalendarBlock $block;

    protected function setUp(): void
    {
        $this->block = new CalendarBlock();
    }

    public function testType(): void
    {
        self::assertSame('calendar', $this->block->type());
    }

    public function testRenderWithSpecificMonth(): void
    {
        $html = $this->block->render([
            'year' => 2026,
            'month' => 3,
            'posts' => [
                ['day' => 14, 'url' => '/2026/03/14'],
            ],
        ]);

        self::assertStringContainsString('March 2026', $html);
        self::assertStringContainsString('role="grid"', $html);
        self::assertStringContainsString('href="/2026/03/14"', $html);
        self::assertStringContainsString('calendar-block__day--has-posts', $html);
    }

    public function testRenderWithNavigation(): void
    {
        $html = $this->block->render([
            'year' => 2026,
            'month' => 6,
            'posts' => [],
            'prevMonthUrl' => '/cal/2026/05',
            'nextMonthUrl' => '/cal/2026/07',
        ]);

        self::assertStringContainsString('href="/cal/2026/05"', $html);
        self::assertStringContainsString('href="/cal/2026/07"', $html);
        self::assertStringContainsString('Previous month', $html);
        self::assertStringContainsString('Next month', $html);
    }

    public function testRenderDefaultsToCurrentMonth(): void
    {
        $html = $this->block->render([]);

        $currentMonth = date('F');
        $currentYear = date('Y');
        self::assertStringContainsString("$currentMonth $currentYear", $html);
    }

    public function testRenderContainsWeekdayHeaders(): void
    {
        $html = $this->block->render(['year' => 2026, 'month' => 1]);

        self::assertStringContainsString('Sun', $html);
        self::assertStringContainsString('Mon', $html);
        self::assertStringContainsString('Sat', $html);
    }

    public function testValidateRejectsInvalidMonth(): void
    {
        $errors = $this->block->validate(['month' => 13]);
        self::assertNotEmpty($errors);
    }

    public function testValidateRejectsInvalidYear(): void
    {
        $errors = $this->block->validate(['year' => 1999]);
        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'year' => 2026,
            'month' => 3,
            'posts' => [
                ['day' => 1, 'url' => '/2026/03/01'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
