<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Helper\LandmarkRegion;

final class LandmarkRegionTest extends TestCase
{
    private LandmarkRegion $landmark;

    protected function setUp(): void
    {
        $this->landmark = new LandmarkRegion();
    }

    #[Test]
    public function main_wraps_in_main_tag(): void
    {
        $html = $this->landmark->main('Content here');

        self::assertStringContainsString('<main>', $html);
        self::assertStringContainsString('Content here', $html);
        self::assertStringContainsString('</main>', $html);
    }

    #[Test]
    public function main_with_label_includes_aria_label(): void
    {
        $html = $this->landmark->main('Content', 'Primary content');

        self::assertStringContainsString('aria-label="Primary content"', $html);
    }

    #[Test]
    public function navigation_includes_aria_label(): void
    {
        $html = $this->landmark->navigation('Links', 'Main navigation');

        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Main navigation"', $html);
        self::assertStringContainsString('Links', $html);
        self::assertStringContainsString('</nav>', $html);
    }

    #[Test]
    public function labels_are_html_escaped(): void
    {
        $html = $this->landmark->navigation('Links', 'Nav & "quotes"');

        self::assertStringContainsString('Nav &amp; &quot;quotes&quot;', $html);
        self::assertStringNotContainsString('Nav & "quotes"', $html);
    }

    #[Test]
    public function banner_renders_header_with_role(): void
    {
        $html = $this->landmark->banner('Header content');

        self::assertStringContainsString('<header role="banner">', $html);
        self::assertStringContainsString('Header content', $html);
    }

    #[Test]
    public function contentinfo_renders_footer_with_role(): void
    {
        $html = $this->landmark->contentinfo('Footer content');

        self::assertStringContainsString('<footer role="contentinfo">', $html);
        self::assertStringContainsString('Footer content', $html);
    }

    #[Test]
    public function complementary_renders_aside_with_label(): void
    {
        $html = $this->landmark->complementary('Sidebar', 'Related content');

        self::assertStringContainsString('<aside', $html);
        self::assertStringContainsString('aria-label="Related content"', $html);
    }

    #[Test]
    public function region_renders_section_with_role_and_label(): void
    {
        $html = $this->landmark->region('Content', 'Search results');

        self::assertStringContainsString('<section', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('aria-label="Search results"', $html);
    }
}
