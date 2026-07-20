<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;

final class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SvgSanitizer();
    }

    #[Test]
    public function sanitize_preserves_valid_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="red"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('<svg', $result);
        self::assertStringContainsString('<rect', $result);
        self::assertStringContainsString('fill="red"', $result);
    }

    #[Test]
    public function sanitize_removes_script_elements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><rect width="10" height="10"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    #[Test]
    public function sanitize_removes_event_handler_attributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" onclick="alert(1)" onload="evil()"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('onload', $result);
    }

    #[Test]
    public function sanitize_removes_event_handlers_and_style_from_the_root_svg_element(): void
    {
        // RC-5: the tree walk filtered descendants' attributes but never the
        // root element's, so an onload on the root <svg> survived and executed
        // — a live stored XSS on any CMS with SVG uploads.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" '
            . 'onload="alert(document.cookie)" style="x:expression(alert(1))">'
            . '<rect width="10" height="10"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('onload', $result);
        self::assertStringNotContainsString('alert', $result);
        self::assertStringNotContainsString('expression', $result);
        // Legitimate root attributes survive.
        self::assertStringContainsString('viewBox', $result);
        self::assertStringContainsString('<rect', $result);
    }

    #[Test]
    public function sanitize_removes_style_attributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" style="background:url(evil)"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('style=', $result);
    }

    #[Test]
    public function sanitize_removes_foreignobject_elements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div>injection</div></foreignObject><rect width="10" height="10"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('foreignObject', $result);
        self::assertStringNotContainsString('injection', $result);
    }

    #[Test]
    public function sanitize_removes_disallowed_elements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><animate attributeName="x"/><rect width="10" height="10"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('<animate', $result);
    }

    #[Test]
    public function sanitize_blocks_javascript_href(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="javascript:alert(1)"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function sanitize_returns_empty_for_empty_input(): void
    {
        $result = $this->sanitizer->sanitize('');

        self::assertSame('', $result);
    }

    #[Test]
    public function sanitize_returns_empty_for_whitespace_input(): void
    {
        $result = $this->sanitizer->sanitize('   ');

        self::assertSame('', $result);
    }

    #[Test]
    public function sanitize_throws_for_invalid_xml(): void
    {
        $this->expectException(CmsException::class);

        $this->sanitizer->sanitize('<not valid xml>>>');
    }

    #[Test]
    public function sanitize_preserves_path_elements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M10 10 L 20 20" stroke="black" fill="none"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('<path', $result);
        self::assertStringContainsString('d="M10 10 L 20 20"', $result);
    }

    #[Test]
    public function sanitize_preserves_allowed_attributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="blue" stroke="black" stroke-width="2"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('cx="50"', $result);
        self::assertStringContainsString('cy="50"', $result);
        self::assertStringContainsString('r="40"', $result);
        self::assertStringContainsString('fill="blue"', $result);
    }
}
