<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
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

    // ── Group 1: Script execution (12 vectors) ──────────────────────────

    #[Test]
    public function removesBasicScriptElement(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    #[Test]
    public function removesTypedScriptElement(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script type="text/javascript">alert(1)</script></svg>');
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function removesOnloadEventHandlerOnChild(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)" width="10" height="10"/></svg>');
        self::assertStringNotContainsString('onload', $result);
    }

    #[Test]
    public function removesOnclickEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)"/></svg>');
        self::assertStringNotContainsString('onclick', $result);
    }

    #[Test]
    public function removesOnerrorEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onerror="alert(1)"/></svg>');
        self::assertStringNotContainsString('onerror', $result);
    }

    #[Test]
    public function removesOnfocusEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onfocus="alert(1)"/></svg>');
        self::assertStringNotContainsString('onfocus', $result);
    }

    #[Test]
    public function removesOnmouseoverEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onmouseover="alert(1)"/></svg>');
        self::assertStringNotContainsString('onmouseover', $result);
    }

    #[Test]
    public function removesAnimateElementEntirely(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><animate attributeName="x" from="0" to="100"/></svg>');
        self::assertStringNotContainsString('<animate', $result);
    }

    #[Test]
    public function removesSetElementEntirely(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="fill" to="red"/></svg>');
        self::assertStringNotContainsString('<set', $result);
    }

    #[Test]
    public function removesOnblurEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onblur="alert(1)" tabindex="0"/></svg>');
        self::assertStringNotContainsString('onblur', $result);
    }

    #[Test]
    public function removesOnchangeEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect onchange="alert(1)"/></svg>');
        self::assertStringNotContainsString('onchange', $result);
    }

    #[Test]
    public function removesOninputEventHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect oninput="alert(1)"/></svg>');
        self::assertStringNotContainsString('oninput', $result);
    }

    // ── Group 2: URL scheme attacks (10 vectors) ────────────────────────

    #[Test]
    public function removesJavascriptHrefOnUse(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('javascript', strtolower($result));
    }

    #[Test]
    public function removesUppercaseJavascriptHref(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="JAVASCRIPT:alert(1)"/></svg>');
        self::assertStringNotContainsString('javascript', strtolower($result));
    }

    #[Test]
    public function removesVbscriptHref(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="vbscript:alert(1)"/></svg>');
        self::assertStringNotContainsString('vbscript', strtolower($result));
    }

    #[Test]
    public function removesDataUriOnUse(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"/></svg>');
        self::assertStringNotContainsString('data:text/html', $result);
    }

    #[Test]
    public function removesXlinkHrefJavascript(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('javascript', strtolower($result));
    }

    #[Test]
    public function removesImageHrefJavascript(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><image href="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('javascript', strtolower($result));
    }

    #[Test]
    public function removesJavascriptOnImageXlinkHref(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('javascript', strtolower($result));
    }

    #[Test]
    public function removesUseHrefWithDataScheme(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="data:image/svg+xml;base64,PHN2Zz4="/></svg>');
        self::assertStringNotContainsString('data:', $result);
    }

    #[Test]
    public function removesVbscriptOnImage(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><image href="vbscript:MsgBox(1)"/></svg>');
        self::assertStringNotContainsString('vbscript', strtolower($result));
    }

    #[Test]
    public function removesMixedCaseVbscript(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="VbScRiPt:alert(1)"/></svg>');
        self::assertStringNotContainsString('vbscript', strtolower($result));
    }

    // ── Group 3: CSS-based attacks (5 vectors) ──────────────────────────

    #[Test]
    public function removesStyleAttributeWithExpression(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect style="background:expression(alert(1))"/></svg>');
        self::assertStringNotContainsString('style', $result);
        self::assertStringNotContainsString('expression', $result);
    }

    #[Test]
    public function removesStyleAttributeWithJavascriptUrl(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect style="background:url(javascript:alert(1))"/></svg>');
        self::assertStringNotContainsString('style', $result);
    }

    #[Test]
    public function removesStyleElement(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><style>rect{background:expression(alert(1))}</style></svg>');
        self::assertStringNotContainsString('<style', $result);
    }

    #[Test]
    public function removesStyleElementWithImport(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><style>@import url("http://evil.com/xss.css");</style></svg>');
        self::assertStringNotContainsString('<style', $result);
    }

    #[Test]
    public function removesMozBindingInStyle(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect style="-moz-binding:url(evil)"/></svg>');
        self::assertStringNotContainsString('style', $result);
    }

    // ── Group 4: External resource loading (5 vectors) ──────────────────

    #[Test]
    public function removesExternalUseHrefHttp(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="http://evil.com/payload.svg#x"/></svg>');
        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function removesExternalUseXlinkHrefHttp(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="http://evil.com/payload.svg#x"/></svg>');
        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function removesExternalImageHref(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil.com/track.gif"/></svg>');
        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function removesExternalUseHttps(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.com/payload.svg#x"/></svg>');
        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function removesExternalImageHttps(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.com/track.gif"/></svg>');
        self::assertStringNotContainsString('evil.com', $result);
    }

    // ── Group 5: Dangerous elements (5 vectors) ─────────────────────────

    #[Test]
    public function removesForeignobject(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>');
        self::assertStringNotContainsString('foreignObject', strtolower($result));
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function removesIframe(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><iframe src="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('<iframe', $result);
    }

    #[Test]
    public function removesEmbed(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><embed src="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('<embed', $result);
    }

    #[Test]
    public function removesObject(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><object data="javascript:alert(1)"/></svg>');
        self::assertStringNotContainsString('<object', $result);
    }

    #[Test]
    public function removesForeignobjectWithEvents(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div onclick="alert(1)">x</div></foreignObject></svg>');
        self::assertStringNotContainsString('foreignobject', strtolower($result));
        self::assertStringNotContainsString('onclick', $result);
    }

    // ── Group 6: Encoding/obfuscation (5 vectors) ───────────────────────

    #[Test]
    public function removesHandlerElementEntirely(): void
    {
        // handler is not in allowlist, removed entirely
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><handler>alert(1)</handler></svg>');
        self::assertStringNotContainsString('<handler', $result);
    }

    #[Test]
    public function removesDisallowedDescScriptContent(): void
    {
        // desc element is allowed, but script inside is blocked
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><desc><script>alert(1)</script></desc></svg>');
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function removesDisallowedFeimageElement(): void
    {
        // feImage is not in the allowlist (feimage lowercase is not present)
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><feImage href="http://evil.com/track.gif"/></svg>');
        // feImage (mixed case) is not in allowlist, gets removed
        self::assertStringNotContainsString('evil.com', $result);
    }

    #[Test]
    public function removesDisallowedAElement(): void
    {
        // <a> is not in the SVG allowlist
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)">click</a></svg>');
        self::assertStringNotContainsString('<a ', $result);
    }

    #[Test]
    public function removesMultipleNestedDangerousElements(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script>x</script><iframe/><embed/><object/></svg>');
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('<iframe', $result);
        self::assertStringNotContainsString('<embed', $result);
        self::assertStringNotContainsString('<object', $result);
    }

    // ── Group 7: Namespace tricks (3 vectors) ────────────────────────────

    #[Test]
    public function removesMathNamespaceElement(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><math><mi>x</mi></math></svg>');
        self::assertStringNotContainsString('<math', $result);
    }

    #[Test]
    public function malformedForeignobjectSvgThrowsOrRemoves(): void
    {
        // foreignObject with XHTML body is either unparseable or gets removed
        // loadXML may fail on mixed-namespace content
        try {
            $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><div xmlns="http://www.w3.org/1999/xhtml">text</div></foreignObject></svg>');
            // If it parses, foreignObject should be removed
            self::assertStringNotContainsString('foreignobject', strtolower($result));
        } catch (CmsException $e) {
            // If it can't parse, that's also acceptable — the sanitizer
            // correctly rejected malformed mixed-namespace SVG content
            self::assertInstanceOf(CmsException::class, $e);
        }
    }

    #[Test]
    public function removesCustomNamespaceHandler(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><handler xmlns:ev="http://www.w3.org/2001/xml-events" ev:event="load">alert(1)</handler></svg>');
        self::assertStringNotContainsString('<handler', $result);
    }

    // ── Group 8: Valid SVG that should PASS (7 vectors) ─────────────────

    #[Test]
    public function allowsBasicCircle(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="red"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<circle', $result);
        self::assertStringContainsString('fill="red"', $result);
    }

    #[Test]
    public function allowsRectWithStroke(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="10" y="10" width="80" height="80" fill="blue" stroke="black"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<rect', $result);
        self::assertStringContainsString('fill="blue"', $result);
        self::assertStringContainsString('stroke="black"', $result);
    }

    #[Test]
    public function allowsTextElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text x="50" y="50" font-size="20">Hello</text></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<text', $result);
        self::assertStringContainsString('Hello', $result);
    }

    #[Test]
    public function allowsPathElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M10 80 C 40 10, 65 10, 95 80 S 150 150, 180 80"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<path', $result);
        self::assertStringContainsString('d="', $result);
    }

    #[Test]
    public function allowsLinearGradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1"><stop offset="0%" stop-color="red"/></linearGradient></defs></svg>';
        $result = $this->sanitizer->sanitize($svg);
        // DOMDocument lowercases XML tag names
        self::assertStringContainsString('lineargradient', strtolower($result));
        self::assertStringContainsString('stop-color="red"', $result);
    }

    #[Test]
    public function allowsPolygonElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polygon points="50,5 20,99 95,39 5,39 80,99" fill="lime"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<polygon', $result);
    }

    #[Test]
    public function allowsEllipseElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><ellipse cx="100" cy="50" rx="100" ry="50" fill="orange"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<ellipse', $result);
    }

    // ── Group 9: Additional security vectors (10+ vectors) ──────────────

    #[Test]
    public function stripsTabindexAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect tabindex="0"/></svg>');
        self::assertStringNotContainsString('tabindex', $result);
    }

    #[Test]
    public function stripsContenteditableAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect contenteditable="true"/></svg>');
        self::assertStringNotContainsString('contenteditable', $result);
    }

    #[Test]
    public function stripsAutofocusAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect autofocus="true"/></svg>');
        self::assertStringNotContainsString('autofocus', $result);
    }

    #[Test]
    public function removesScriptWithCdata(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script><![CDATA[alert(1)]]></script></svg>');
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    #[Test]
    public function preservesViewboxAttribute(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('viewBox', $result);
    }

    #[Test]
    public function preservesTransformAttribute(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g transform="rotate(45)"><rect width="10" height="10"/></g></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('transform="rotate(45)"', $result);
    }

    #[Test]
    public function preservesIdAndClassAttributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect id="main" class="highlight" width="10" height="10"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('id="main"', $result);
        self::assertStringContainsString('class="highlight"', $result);
    }

    #[Test]
    public function animationElementsRemovedBySecurityPolicy(): void
    {
        // animate, animatetransform, animatemotion, set were removed from allowlist
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"><animate attributeName="x" from="0" to="100" dur="2s"/></rect></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringNotContainsString('<animate', $result);
        self::assertStringContainsString('<rect', $result);
    }

    #[Test]
    public function preservesFilterElements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="blur"><feGaussianBlur stdDeviation="5"/></filter></defs></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<filter', $result);
        self::assertStringContainsString('fegaussianblur', strtolower($result));
    }

    #[Test]
    public function preservesMaskElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><mask id="m1"><rect width="100" height="100" fill="white"/></mask></defs></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<mask', $result);
    }

    // ── Edge cases ──────────────────────────────────────────────────────

    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        self::assertSame('', $this->sanitizer->sanitize(''));
    }

    #[Test]
    public function whitespaceOnlyReturnsEmpty(): void
    {
        self::assertSame('', $this->sanitizer->sanitize('   '));
    }

    #[Test]
    public function invalidXmlThrowsCmsException(): void
    {
        $this->expectException(CmsException::class);
        $this->sanitizer->sanitize('<svg><not-closed');
    }

    #[Test]
    public function nonSvgXmlRootStripsDisallowedChildren(): void
    {
        // Non-SVG root: children not in allowlist are removed
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><div>not allowed</div></svg>');
        self::assertStringNotContainsString('<div', $result);
        self::assertStringNotContainsString('not allowed', $result);
    }

    #[Test]
    public function deeplyNestedAllowedElements(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g><g><g><rect width="10" height="10"/></g></g></g></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<rect', $result);
    }

    #[Test]
    public function removesAllEventsViaDataProvider(): void
    {
        $events = [
            'onactivate', 'onbegin', 'onend', 'onfocusin', 'onfocusout',
            'onload', 'onmousedown', 'onmouseenter', 'onmouseleave',
            'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup',
            'onresize', 'onscroll', 'onunload',
        ];

        foreach ($events as $event) {
            $result = $this->sanitizer->sanitize(
                '<svg xmlns="http://www.w3.org/2000/svg"><rect ' . $event . '="alert(1)" width="10" height="10"/></svg>',
            );
            self::assertStringNotContainsString($event, $result, "Event handler '{$event}' was not removed");
        }
    }

    #[Test]
    public function complexValidSvgWithGradientsAndFilters(): void
    {
        $svg = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
                <defs>
                    <linearGradient id="grad1">
                        <stop offset="0%" stop-color="red"/>
                        <stop offset="100%" stop-color="blue"/>
                    </linearGradient>
                    <filter id="shadow">
                        <feGaussianBlur stdDeviation="3"/>
                        <feOffset dx="2" dy="2"/>
                    </filter>
                </defs>
                <circle cx="100" cy="100" r="80" fill="url(#grad1)" filter="url(#shadow)"/>
                <text x="100" y="100" text-anchor="middle" font-size="16">SVG</text>
            </svg>
            SVG;

        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<circle', $result);
        self::assertStringContainsString('<text', $result);
        self::assertStringContainsString('lineargradient', strtolower($result));
        self::assertStringContainsString('<filter', $result);
    }

    // ── Additional vectors to reach 50+ ─────────────────────────────────

    #[Test]
    public function removesDisallowedAudioElement(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><audio src="evil.mp3"/></svg>');
        self::assertStringNotContainsString('<audio', $result);
    }

    #[Test]
    public function removesDisallowedVideoElement(): void
    {
        $result = $this->sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><video src="evil.mp4"/></svg>');
        self::assertStringNotContainsString('<video', $result);
    }

    #[Test]
    public function allowsLocalUseFragmentReference(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><rect id="r1" width="10" height="10"/></defs><use href="#r1"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<use', $result);
        self::assertStringContainsString('#r1', $result);
    }

    #[Test]
    public function allowsSymbolElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><symbol id="icon"><circle cx="5" cy="5" r="5"/></symbol></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<symbol', $result);
    }

    #[Test]
    public function allowsMarkerElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><marker id="arrowhead" markerWidth="10" markerHeight="7" orient="auto"><polygon points="0 0, 10 3.5, 0 7"/></marker></defs></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<marker', $result);
    }

    #[Test]
    public function allowsPatternElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dots" patternUnits="userSpaceOnUse" width="10" height="10"><circle cx="5" cy="5" r="2" fill="gray"/></pattern></defs></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('<pattern', $result);
    }

    #[Test]
    public function allowsClippathElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="clip"><circle cx="50" cy="50" r="50"/></clipPath></defs></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('clippath', strtolower($result));
    }

    #[Test]
    public function preservesStrokeAttributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="0" x2="100" y2="100" stroke="red" stroke-width="2" stroke-dasharray="5,5" stroke-linecap="round" stroke-linejoin="bevel"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('stroke-width="2"', $result);
        self::assertStringContainsString('stroke-dasharray="5,5"', $result);
        self::assertStringContainsString('stroke-linecap="round"', $result);
        self::assertStringContainsString('stroke-linejoin="bevel"', $result);
    }

    #[Test]
    public function preservesOpacityAttributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" opacity="0.5" fill-opacity="0.8" stroke-opacity="0.3"/></svg>';
        $result = $this->sanitizer->sanitize($svg);
        self::assertStringContainsString('opacity="0.5"', $result);
        self::assertStringContainsString('fill-opacity="0.8"', $result);
        self::assertStringContainsString('stroke-opacity="0.3"', $result);
    }
}
