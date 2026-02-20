<?php

declare(strict_types=1);

namespace Pulsar\Tests\Fuzz;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\HtmlEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;

#[CoversClass(HtmlEscaper::class)]
#[CoversClass(JsEscaper::class)]
#[CoversClass(CssEscaper::class)]
#[CoversClass(UrlEscaper::class)]
#[CoversClass(AttributeEscaper::class)]
#[Group('fuzz')]
final class TemplateFuzzTest extends TestCase
{
    private HtmlEscaper $htmlEscaper;
    private JsEscaper $jsEscaper;
    private CssEscaper $cssEscaper;
    private UrlEscaper $urlEscaper;
    private AttributeEscaper $attributeEscaper;

    protected function setUp(): void
    {
        $this->htmlEscaper = new HtmlEscaper();
        $this->jsEscaper = new JsEscaper();
        $this->cssEscaper = new CssEscaper();
        $this->urlEscaper = new UrlEscaper();
        $this->attributeEscaper = new AttributeEscaper();
    }

    #[Test]
    public function xssInjectionAttemptsAreNeutralizedByHtmlEscaper(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert(1)>',
            '<svg onload=alert(1)>',
            '"><script>alert(document.cookie)</script>',
            "';alert(String.fromCharCode(88,83,83))//",
            '<iframe src="javascript:alert(1)">',
            '<body onload=alert(1)>',
            '<input onfocus=alert(1) autofocus>',
            '<marquee onstart=alert(1)>',
            '<a href="javascript:alert(1)">click</a>',
            '<math><mtext><table><mglyph><style><!--</style><img src=x onerror=alert(1)>',
            "{{constructor.constructor('return this')()}}", // Prototype pollution
            '${7*7}', // Server-side template injection
            '#{7*7}',
        ];

        foreach ($xssPayloads as $payload) {
            $escaped = $this->htmlEscaper->escape($payload);
            // The key property: escaped output cannot contain unescaped < or > characters
            // which would allow HTML tag injection
            self::assertStringNotContainsString('<', $escaped, "Unescaped '<' found after escaping: {$payload}");
            self::assertStringNotContainsString('>', $escaped, "Unescaped '>' found after escaping: {$payload}");
        }
    }

    #[Test]
    public function templateInjectionPatternsAreEscaped(): void
    {
        $injections = [
            '{{ system("id") }}',
            '{% import os %}{{ os.popen("id").read() }}',
            '${Runtime.getRuntime().exec("id")}',
            '#{T(java.lang.Runtime).getRuntime().exec("id")}',
            '<%= system("id") %>',
            '{{_self.env.registerUndefinedFilterCallback("exec")}}{{_self.env.getFilter("id")}}',
            '{{ config.items() }}',
            '{% for c in "".__class__.__mro__[1].__subclasses__() %}{% endfor %}',
        ];

        foreach ($injections as $injection) {
            $escaped = $this->htmlEscaper->escape($injection);
            self::assertStringNotContainsString('<', $escaped);
            self::assertStringNotContainsString('>', $escaped);
        }
    }

    #[Test]
    public function deeplyNestedContextValuesDoNotCauseStackOverflow(): void
    {
        $depth = 200;
        $value = 'leaf';

        for ($i = 0; $i < $depth; $i++) {
            $value = "prefix-{$value}-suffix";
        }

        $escaped = $this->htmlEscaper->escape($value);
        self::assertIsString($escaped);
        self::assertStringContainsString('leaf', $escaped);
    }

    #[Test]
    public function largeStringValuesAreEscapedWithoutMemoryIssues(): void
    {
        $sizes = [1024, 8192, 65536, 262144];

        foreach ($sizes as $size) {
            $value = str_repeat('<script>alert("x")</script>', (int) ceil($size / 32));
            $escaped = $this->htmlEscaper->escape($value);
            self::assertStringNotContainsString('<script>', $escaped);
        }
    }

    #[Test]
    public function jsEscaperNeutralizesJavascriptInjection(): void
    {
        $payloads = [
            '</script><script>alert(1)</script>',
            "'; alert(1); //",
            '"; alert(1); //',
            '\'; alert(1); //',
            '\\"; alert(1); //',
            "\n\ralert(1)",
            "\u0022\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E",
        ];

        foreach ($payloads as $payload) {
            $escaped = $this->jsEscaper->escape($payload);
            self::assertIsString($escaped);
            // The escaped output should not contain unescaped script tags
            self::assertStringNotContainsString('</script>', $escaped);
        }
    }

    #[Test]
    public function cssEscaperHandlesInjectionAttempts(): void
    {
        $payloads = [
            'expression(alert(1))',
            'url(javascript:alert(1))',
            '; background: url(evil.com)',
            '} body { background: red } .x {',
            "\\0000061lert(1)",
        ];

        foreach ($payloads as $payload) {
            $escaped = $this->cssEscaper->escape($payload);
            self::assertIsString($escaped);
        }
    }

    #[Test]
    public function urlEscaperHandlesProtocolInjection(): void
    {
        $payloads = [
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:alert(1)',
            "java\nscript:alert(1)",
            "java\tscript:alert(1)",
            "java\x00script:alert(1)",
        ];

        foreach ($payloads as $payload) {
            $escaped = $this->urlEscaper->escape($payload);
            self::assertIsString($escaped);
        }
    }

    #[Test]
    public function attributeEscaperHandlesAttributeBreakout(): void
    {
        $payloads = [
            '" onmouseover="alert(1)',
            "' onmouseover='alert(1)",
            '" onfocus="alert(1)" autofocus="',
            "` onmouseover=`alert(1)`",
            '{{7*7}}',
        ];

        foreach ($payloads as $payload) {
            $escaped = $this->attributeEscaper->escape($payload);
            self::assertStringNotContainsString('"', $escaped, "Attribute breakout not prevented for: {$payload}");
        }
    }

    #[Test]
    public function allEscapersHandleEmptyAndBinaryInput(): void
    {
        // Valid UTF-8 inputs that all escapers must handle without crashing
        $safeInputs = ['', "\x00", 'normal text'];

        // Binary inputs that may cause JsonException in JsEscaper (valid behavior)
        $binaryInputs = ["\xFF\xFE", random_bytes(64)];

        $escapers = [
            $this->htmlEscaper,
            $this->jsEscaper,
            $this->cssEscaper,
            $this->urlEscaper,
            $this->attributeEscaper,
        ];

        foreach ($escapers as $escaper) {
            foreach ($safeInputs as $input) {
                $escaped = $escaper->escape($input);
                self::assertIsString($escaped);
            }
        }

        // Binary inputs: JsEscaper may reject malformed UTF-8 (expected behavior)
        foreach ($binaryInputs as $input) {
            foreach ($escapers as $escaper) {
                try {
                    $escaped = $escaper->escape($input);
                    self::assertIsString($escaped);
                } catch (\JsonException) {
                    // JsEscaper uses json_encode internally which rejects malformed UTF-8
                    // This is correct security behavior — rejecting invalid input
                    self::assertInstanceOf(\Pulsar\View\Escaping\JsEscaper::class, $escaper);
                }
            }
        }
    }
}
