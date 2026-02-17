<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\FullSiteEditor;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FullSiteEditor\TemplatePart;
use Pulsar\Extension\Cms\FullSiteEditor\TemplatePartArea;

final class TemplatePartTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-01-15 12:00:00');

        $part = new TemplatePart(
            id: 'tp-001',
            slug: 'header',
            area: TemplatePartArea::Header,
            name: 'Site Header',
            content: '{"blocks":[]}',
            themeId: 'theme-default',
            updatedAt: $now,
        );

        self::assertSame('tp-001', $part->id);
        self::assertSame('header', $part->slug);
        self::assertSame(TemplatePartArea::Header, $part->area);
        self::assertSame('Site Header', $part->name);
        self::assertSame('{"blocks":[]}', $part->content);
        self::assertSame('theme-default', $part->themeId);
        self::assertSame($now, $part->updatedAt);
    }

    #[Test]
    public function defaultsToEmptyThemeIdAndCurrentTimestamp(): void
    {
        $before = new DateTimeImmutable();

        $part = new TemplatePart(
            id: 'tp-002',
            slug: 'footer',
            area: TemplatePartArea::Footer,
            name: 'Footer',
            content: '[]',
        );

        self::assertSame('', $part->themeId);
        self::assertGreaterThanOrEqual($before, $part->updatedAt);
    }

    #[Test]
    #[DataProvider('areaProvider')]
    public function supportsAllAreas(TemplatePartArea $area, string $expectedValue): void
    {
        $part = new TemplatePart(
            id: 'tp-003',
            slug: 'test',
            area: $area,
            name: 'Test',
            content: '[]',
        );

        self::assertSame($expectedValue, $part->area->value);
    }

    /**
     * @return iterable<string, array{TemplatePartArea, string}>
     */
    public static function areaProvider(): iterable
    {
        yield 'header' => [TemplatePartArea::Header, 'header'];
        yield 'footer' => [TemplatePartArea::Footer, 'footer'];
        yield 'sidebar' => [TemplatePartArea::Sidebar, 'sidebar'];
        yield 'navigation' => [TemplatePartArea::Navigation, 'navigation'];
        yield 'content area' => [TemplatePartArea::ContentArea, 'content_area'];
        yield 'custom' => [TemplatePartArea::Custom, 'custom'];
    }
}
