<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Override;
use Pulsar\Api\Internal;

/**
 * PDF viewer directive: renders a branded embedded PDF viewer.
 *
 * Usage: {@}pdf($mediaId) or {@}pdf($mediaId, ['download' => true])
 *
 * Compiles to a PHP block that resolves the media asset by ID and renders
 * the Pulsar branded PDF viewer with page navigation, zoom controls,
 * fullscreen mode, and optional download button.
 *
 * Requires pdf-viewer.js and pdf-viewer.css to be loaded on the page.
 * PDF.js library must be available (loaded via CDN or bundled).
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class PdfDirective implements DirectiveInterface
{
    #[Override]
    public function name(): string
    {
        return 'pdf';
    }

    #[Override]
    public function compile(string $expression): string
    {
        return <<<PHP
            <?php
            \$__pdfArgs = [{$expression}];
            \$__pdfId = \$__pdfArgs[0] ?? null;
            \$__pdfOpts = \$__pdfArgs[1] ?? [];
            \$__pdfSrc = \$__pdfOpts['src'] ?? null;
            \$__pdfTitle = \$__pdfOpts['title'] ?? 'PDF document';
            \$__pdfDownload = \$__pdfOpts['download'] ?? true;
            \$__pdfHeight = \$__pdfOpts['height'] ?? 700;
            \$__pdfInitialPage = \$__pdfOpts['page'] ?? 1;
            \$__pdfInitialZoom = \$__pdfOpts['zoom'] ?? 'auto';

            if (\$__pdfId !== null && \$__pdfSrc === null && isset(\$__mediaService)) {
                \$__pdfAsset = \$__mediaService->find(\$__pdfId);
                if (\$__pdfAsset !== null) {
                    \$__pdfSrc = \$__pdfAsset->url;
                    \$__pdfTitle = \$__pdfTitle !== 'PDF document' ? \$__pdfTitle : (\$__pdfAsset->title ?? 'PDF document');
                }
            }
            ?>
            <div class="pui-pdf-viewer" data-pui-pdf data-pdf-src="<?= htmlspecialchars(\$__pdfSrc ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-initial-page="<?= (int)\$__pdfInitialPage ?>" data-initial-zoom="<?= htmlspecialchars((string)\$__pdfInitialZoom, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" role="region" aria-label="<?= htmlspecialchars(\$__pdfTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" style="height:<?= (int)\$__pdfHeight ?>px">
              <div class="pui-pdf-viewer__toolbar" role="toolbar" aria-label="PDF controls">
                <div class="pui-pdf-viewer__nav">
                  <button type="button" class="pui-pdf-viewer__btn" data-action="prev-page" aria-label="Previous page" disabled>&lsaquo;</button>
                  <span class="pui-pdf-viewer__page-info"><span data-page="current">1</span> / <span data-page="total">1</span></span>
                  <button type="button" class="pui-pdf-viewer__btn" data-action="next-page" aria-label="Next page" disabled>&rsaquo;</button>
                </div>
                <div class="pui-pdf-viewer__zoom">
                  <button type="button" class="pui-pdf-viewer__btn" data-action="zoom-out" aria-label="Zoom out">&minus;</button>
                  <span class="pui-pdf-viewer__zoom-level" data-zoom-display>100%</span>
                  <button type="button" class="pui-pdf-viewer__btn" data-action="zoom-in" aria-label="Zoom in">&plus;</button>
                </div>
                <div class="pui-pdf-viewer__actions">
                  <button type="button" class="pui-pdf-viewer__btn" data-action="fullscreen" aria-label="Fullscreen">&#x26F6;</button>
                  <?php if (\$__pdfDownload && \$__pdfSrc): ?>
                    <a href="<?= htmlspecialchars(\$__pdfSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="pui-pdf-viewer__btn" download aria-label="Download PDF">&#x21E9;</a>
                  <?php endif; ?>
                </div>
              </div>
              <div class="pui-pdf-viewer__canvas-container">
                <canvas class="pui-pdf-viewer__canvas"></canvas>
              </div>
              <noscript>
                <p>JavaScript is required to view this PDF. <a href="<?= htmlspecialchars(\$__pdfSrc ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Download the PDF</a> instead.</p>
              </noscript>
            </div>
            <?php unset(\$__pdfArgs, \$__pdfId, \$__pdfOpts, \$__pdfSrc, \$__pdfTitle, \$__pdfDownload, \$__pdfHeight, \$__pdfInitialPage, \$__pdfInitialZoom, \$__pdfAsset); ?>
            PHP;
    }
}
