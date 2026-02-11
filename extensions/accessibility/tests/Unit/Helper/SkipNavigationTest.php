<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Helper\SkipNavigation;

final class SkipNavigationTest extends TestCase
{
    private SkipNavigation $skip;

    protected function setUp(): void
    {
        $this->skip = new SkipNavigation();
    }

    #[Test]
    public function default_render_contains_correct_class_and_href(): void
    {
        $html = $this->skip->render();

        self::assertStringContainsString('class="pui-skip-link"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
        self::assertStringContainsString('Skip to main content', $html);
    }

    #[Test]
    public function custom_target_id_is_escaped(): void
    {
        $html = $this->skip->render('my"target');

        self::assertStringContainsString('href="#my&quot;target"', $html);
        self::assertStringNotContainsString('href="#my"target"', $html);
    }

    #[Test]
    public function custom_target_id_renders_correctly(): void
    {
        $html = $this->skip->render('content-area');

        self::assertStringContainsString('href="#content-area"', $html);
    }

    #[Test]
    public function output_is_an_anchor_tag(): void
    {
        $html = $this->skip->render();

        self::assertStringStartsWith('<a ', $html);
        self::assertStringEndsWith('</a>', $html);
    }
}
