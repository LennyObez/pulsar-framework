<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\TimeTrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapRenderer;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapService;

use function preg_match;
use function str_contains;

#[CoversClass(TimeTrapRenderer::class)]
final class TimeTrapRendererTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes

    protected function tearDown(): void
    {
        TimeTrapRenderer::resetGlobalInstance();
    }

    private function renderer(string $field = 'pulsar-form-ts'): TimeTrapRenderer
    {
        return new TimeTrapRenderer(new TimeTrapService(self::KEY), $field);
    }

    #[Test]
    public function renderEmitsAHiddenFieldWithASignedTokenAndNoScript(): void
    {
        $html = $this->renderer()->render('contact');

        // No-JS: a hidden input carrying the stamp, and absolutely no script.
        self::assertStringContainsString('<input type="hidden"', $html);
        self::assertStringContainsString('name="pulsar-form-ts"', $html);
        self::assertFalse(str_contains($html, '<script'));
        self::assertFalse(str_contains($html, 'javascript:'));

        // The value is a non-empty token that the service can verify.
        self::assertSame(1, preg_match('/value="([^"]+)"/', $html, $m));
        $service = new TimeTrapService(self::KEY);
        $parsed = $service->parse($m[1]);
        self::assertNotNull($parsed);
        self::assertSame('contact', $parsed->formId);
    }

    #[Test]
    public function tokensForDifferentFormsDiffer(): void
    {
        // The stamp is intentionally deterministic per (second, formId) — it is a
        // timing proof, not a single-use nonce — but the form binding must be
        // reflected, so two different forms produce different tokens.
        $renderer = $this->renderer();

        self::assertNotSame($renderer->render('contact'), $renderer->render('newsletter'));
    }

    #[Test]
    public function renderGlobalReturnsEmptyStringWhenDisabled(): void
    {
        // No global instance set ⇒ the @timetrap / @shield directive degrades quietly.
        self::assertSame('', TimeTrapRenderer::renderGlobal('contact'));
    }

    #[Test]
    public function renderGlobalDelegatesToTheConfiguredInstance(): void
    {
        TimeTrapRenderer::setGlobalInstance($this->renderer());

        $html = TimeTrapRenderer::renderGlobal('contact');

        self::assertStringContainsString('name="pulsar-form-ts"', $html);
        self::assertFalse(str_contains($html, '<script'));
    }

    #[Test]
    public function fieldNameAndTokenAreHtmlEscaped(): void
    {
        $html = $this->renderer('a"b')->render('contact');

        // The quote in the field name must be entity-escaped, not break the attribute.
        self::assertStringContainsString('name="a&quot;b"', $html);
    }
}
