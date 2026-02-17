<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\PdfDirective;

#[CoversClass(PdfDirective::class)]
final class PdfDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsPdf(): void
    {
        $directive = new PdfDirective();

        self::assertSame('pdf', $directive->name());
    }

    #[Test]
    public function compileProducesPdfViewerMarkup(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-pdf-viewer', $output);
        self::assertStringContainsString('data-pui-pdf', $output);
        self::assertStringContainsString('<canvas', $output);
    }

    #[Test]
    public function compileIncludesPageNavigation(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-action="prev-page"', $output);
        self::assertStringContainsString('data-action="next-page"', $output);
        self::assertStringContainsString('data-page="current"', $output);
        self::assertStringContainsString('data-page="total"', $output);
    }

    #[Test]
    public function compileIncludesZoomControls(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-action="zoom-in"', $output);
        self::assertStringContainsString('data-action="zoom-out"', $output);
        self::assertStringContainsString('data-zoom-display', $output);
    }

    #[Test]
    public function compileIncludesFullscreenButton(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-action="fullscreen"', $output);
        self::assertStringContainsString('aria-label="Fullscreen"', $output);
    }

    #[Test]
    public function compileIncludesDownloadLink(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('download', $output);
        self::assertStringContainsString('aria-label="Download PDF"', $output);
    }

    #[Test]
    public function compileIncludesNoscriptFallback(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('<noscript>', $output);
        self::assertStringContainsString('JavaScript is required', $output);
        self::assertStringContainsString('Download the PDF', $output);
    }

    #[Test]
    public function compileEscapesOutputWithHtmlspecialchars(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('ENT_QUOTES', $output);
    }

    #[Test]
    public function compileIncludesAccessibilityAttributes(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('role="region"', $output);
        self::assertStringContainsString('role="toolbar"', $output);
        self::assertStringContainsString('aria-label="Previous page"', $output);
        self::assertStringContainsString('aria-label="Next page"', $output);
        self::assertStringContainsString('aria-label="Zoom in"', $output);
        self::assertStringContainsString('aria-label="Zoom out"', $output);
    }

    #[Test]
    public function compileUnsetsTemporaryVariables(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('unset(', $output);
        self::assertStringContainsString('$__pdfArgs', $output);
        self::assertStringContainsString('$__pdfId', $output);
    }

    #[Test]
    public function compileIncludesPdfSrcDataAttribute(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-pdf-src', $output);
        self::assertStringContainsString('data-initial-page', $output);
        self::assertStringContainsString('data-initial-zoom', $output);
    }

    #[Test]
    public function compileIncludesCanvasContainer(): void
    {
        $directive = new PdfDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-pdf-viewer__canvas-container', $output);
        self::assertStringContainsString('pui-pdf-viewer__canvas', $output);
    }
}
