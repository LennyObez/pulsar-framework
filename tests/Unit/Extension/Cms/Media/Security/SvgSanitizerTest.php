<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;

#[CoversClass(SvgSanitizer::class)]
final class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SvgSanitizer();
    }

    #[Test]
    public function sanitizeEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->sanitizer->sanitize(''));
    }

    #[Test]
    public function sanitizeWhitespaceOnlyReturnsEmpty(): void
    {
        self::assertSame('', $this->sanitizer->sanitize('   '));
    }

    #[Test]
    public function sanitizeThrowsOnInvalidXml(): void
    {
        $this->expectException(CmsException::class);
        $this->sanitizer->sanitize('<not-valid-xml');
    }

    #[Test]
    public function sanitizePreservesCleanSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="red"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('<circle', $result);
        self::assertStringContainsString('cx="50"', $result);
        self::assertStringContainsString('fill="red"', $result);
    }

    #[Test]
    #[DataProvider('blockedElementProvider')]
    public function sanitizeRemovesBlockedElements(string $element): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><' . $element . '>malicious</' . $element . '></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('<' . $element, $result);
        self::assertStringNotContainsString('malicious', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedElementProvider(): iterable
    {
        yield 'script' => ['script'];
        yield 'foreignobject' => ['foreignobject'];
        yield 'iframe' => ['iframe'];
        yield 'embed' => ['embed'];
        yield 'object' => ['object'];
    }

    #[Test]
    public function sanitizeRemovesEventHandlers(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" onclick="alert(1)" onload="evil()"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('onload', $result);
        self::assertStringContainsString('<circle', $result);
    }

    #[Test]
    public function sanitizeRemovesStyleAttributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect style="background:url(evil)" width="10" height="10"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('style=', $result);
        self::assertStringContainsString('width="10"', $result);
    }

    #[Test]
    public function sanitizeRemovesDisallowedElements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><animate attributeName="x" from="0" to="100"/><circle cx="50" cy="50" r="40"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('<animate', $result);
        self::assertStringContainsString('<circle', $result);
    }

    #[Test]
    public function sanitizeRemovesDisallowedAttributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" data-custom="value" aria-label="test"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('data-custom', $result);
        self::assertStringNotContainsString('aria-label', $result);
    }

    #[Test]
    public function sanitizeRemovesJavascriptHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="javascript:alert(1)"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function sanitizeRemovesVbscriptHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="vbscript:MsgBox"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('vbscript:', $result);
    }

    #[Test]
    public function sanitizeBlocksExternalUseHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.com/sprite.svg#icon"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function sanitizeAllowsInternalUseHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><symbol id="icon"><circle cx="10" cy="10" r="5"/></symbol></defs><use href="#icon"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('href="#icon"', $result);
    }

    #[Test]
    public function sanitizeBlocksExternalImageHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.com/image.png" width="100" height="100"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function sanitizeRemovesProcessingInstructions(): void
    {
        $svg = '<?xml-stylesheet type="text/css" href="evil.css"?><svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('xml-stylesheet', $result);
        self::assertStringContainsString('<circle', $result);
    }

    #[Test]
    public function sanitizePreservesAllowedElements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g><rect x="0" y="0" width="100" height="100"/><text x="10" y="50">Hello</text><path d="M0 0L100 100"/></g></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('<g>', $result);
        self::assertStringContainsString('<rect', $result);
        self::assertStringContainsString('<text', $result);
        self::assertStringContainsString('<path', $result);
    }

    #[Test]
    public function sanitizeHandlesNestedMaliciousContent(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g><script>alert(1)</script><circle cx="50" cy="50" r="40"/></g></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('<script', $result);
        self::assertStringContainsString('<circle', $result);
    }

    #[Test]
    public function sanitizeBlocksDataUriOnUse(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('data:', $result);
    }

    #[Test]
    public function sanitizePreservesGradients(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="grad"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient></defs><rect fill="url(#grad)" width="100" height="100"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        // linearGradient is allowed (lowercase comparison)
        self::assertStringContainsString('stop-color', $result);
    }
}
