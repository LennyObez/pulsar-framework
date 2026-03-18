/**
 * Pulsar UI — PDF Viewer v1.0.0
 *
 * Branded embedded PDF viewer with page navigation, zoom controls,
 * fullscreen mode, and keyboard accessibility.
 * Requires PDF.js library — MUST be self-hosted (CDN-DOC audit), see
 * the deployment note below.
 *
 * Self-host PDF.js (do NOT load from a third-party CDN: GDPR, CSP, SRI):
 * vendor `pdfjs-dist` into `resources/ui/vendor/pdfjs-dist/` and reference
 * the local copy from your template, e.g.:
 *   <script src="/static/vendor/pdfjs-dist/pdf.min.mjs" type="module"></script>
 *
 * The worker source must point to your local copy as well:
 *   pdfjsLib.GlobalWorkerOptions.workerSrc = '/static/vendor/pdfjs-dist/pdf.worker.min.js';
 */
'use strict';

(function () {
  var MIN_ZOOM = 0.25;
  var MAX_ZOOM = 4.0;
  var ZOOM_STEP = 0.25;

  function initPdfViewer(container) {
    if (typeof pdfjsLib === 'undefined') {
      container.querySelector('.pui-pdf-viewer__canvas-container').textContent =
        'PDF.js library is required. See documentation for setup instructions.';
      return;
    }

    var pdfSrc = container.dataset.pdfSrc;
    if (!pdfSrc) return;

    var canvas = container.querySelector('.pui-pdf-viewer__canvas');
    if (!canvas) return;

    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    var prevBtn = container.querySelector('[data-action="prev-page"]');
    var nextBtn = container.querySelector('[data-action="next-page"]');
    var zoomInBtn = container.querySelector('[data-action="zoom-in"]');
    var zoomOutBtn = container.querySelector('[data-action="zoom-out"]');
    var fullscreenBtn = container.querySelector('[data-action="fullscreen"]');
    var currentPageEl = container.querySelector('[data-page="current"]');
    var totalPagesEl = container.querySelector('[data-page="total"]');
    var zoomDisplay = container.querySelector('[data-zoom-display]');

    var initialPage = parseInt(container.dataset.initialPage, 10) || 1;
    var initialZoom = container.dataset.initialZoom || 'auto';

    var state = {
      pdf: null,
      currentPage: initialPage,
      totalPages: 0,
      zoom: 1.0,
      rendering: false,
    };

    // Load PDF
    var loadingTask = pdfjsLib.getDocument(pdfSrc);
    loadingTask.promise.then(function (pdf) {
      state.pdf = pdf;
      state.totalPages = pdf.numPages;

      if (totalPagesEl) totalPagesEl.textContent = String(state.totalPages);

      // Calculate initial zoom
      if (initialZoom === 'auto') {
        pdf.getPage(1).then(function (page) {
          var viewport = page.getViewport({ scale: 1.0 });
          var containerWidth =
            container.querySelector('.pui-pdf-viewer__canvas-container').clientWidth - 32;
          state.zoom = Math.min(containerWidth / viewport.width, 2.0);
          state.zoom = Math.max(state.zoom, MIN_ZOOM);
          renderPage(state.currentPage);
        });
      } else {
        state.zoom = parseFloat(initialZoom) || 1.0;
        renderPage(state.currentPage);
      }

      updateButtons();
    });

    function renderPage(pageNumber) {
      if (!state.pdf || state.rendering) return;
      state.rendering = true;

      state.pdf.getPage(pageNumber).then(function (page) {
        var viewport = page.getViewport({ scale: state.zoom });
        var dpr = window.devicePixelRatio || 1;

        canvas.width = Math.floor(viewport.width * dpr);
        canvas.height = Math.floor(viewport.height * dpr);
        canvas.style.width = Math.floor(viewport.width) + 'px';
        canvas.style.height = Math.floor(viewport.height) + 'px';

        var renderContext = {
          canvasContext: ctx,
          viewport: viewport,
          transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null,
        };

        page.render(renderContext).promise.then(function () {
          state.rendering = false;
          state.currentPage = pageNumber;

          if (currentPageEl) currentPageEl.textContent = String(state.currentPage);
          if (zoomDisplay) zoomDisplay.textContent = Math.round(state.zoom * 100) + '%';

          updateButtons();
        });
      });
    }

    function updateButtons() {
      if (prevBtn) prevBtn.disabled = state.currentPage <= 1;
      if (nextBtn) nextBtn.disabled = state.currentPage >= state.totalPages;
      if (zoomOutBtn) zoomOutBtn.disabled = state.zoom <= MIN_ZOOM;
      if (zoomInBtn) zoomInBtn.disabled = state.zoom >= MAX_ZOOM;
    }

    // Page navigation
    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        if (state.currentPage > 1) {
          renderPage(state.currentPage - 1);
        }
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (state.currentPage < state.totalPages) {
          renderPage(state.currentPage + 1);
        }
      });
    }

    // Zoom controls
    if (zoomInBtn) {
      zoomInBtn.addEventListener('click', function () {
        if (state.zoom < MAX_ZOOM) {
          state.zoom = Math.min(state.zoom + ZOOM_STEP, MAX_ZOOM);
          renderPage(state.currentPage);
        }
      });
    }

    if (zoomOutBtn) {
      zoomOutBtn.addEventListener('click', function () {
        if (state.zoom > MIN_ZOOM) {
          state.zoom = Math.max(state.zoom - ZOOM_STEP, MIN_ZOOM);
          renderPage(state.currentPage);
        }
      });
    }

    // Fullscreen
    if (fullscreenBtn) {
      fullscreenBtn.addEventListener('click', function () {
        if (document.fullscreenElement === container) {
          document.exitFullscreen();
        } else {
          container.requestFullscreen();
        }
      });
    }

    // Keyboard shortcuts
    container.addEventListener('keydown', function (e) {
      switch (e.key) {
        case 'ArrowLeft':
        case 'PageUp':
          e.preventDefault();
          if (state.currentPage > 1) renderPage(state.currentPage - 1);
          break;
        case 'ArrowRight':
        case 'PageDown':
          e.preventDefault();
          if (state.currentPage < state.totalPages) renderPage(state.currentPage + 1);
          break;
        case '+':
        case '=':
          e.preventDefault();
          if (state.zoom < MAX_ZOOM) {
            state.zoom = Math.min(state.zoom + ZOOM_STEP, MAX_ZOOM);
            renderPage(state.currentPage);
          }
          break;
        case '-':
          e.preventDefault();
          if (state.zoom > MIN_ZOOM) {
            state.zoom = Math.max(state.zoom - ZOOM_STEP, MIN_ZOOM);
            renderPage(state.currentPage);
          }
          break;
        case 'Home':
          e.preventDefault();
          if (state.currentPage !== 1) renderPage(1);
          break;
        case 'End':
          e.preventDefault();
          if (state.currentPage !== state.totalPages) renderPage(state.totalPages);
          break;
        case 'f':
          e.preventDefault();
          if (document.fullscreenElement === container) {
            document.exitFullscreen();
          } else {
            container.requestFullscreen();
          }
          break;
      }
    });

    // Make container focusable for keyboard events
    if (!container.hasAttribute('tabindex')) {
      container.setAttribute('tabindex', '0');
    }
  }

  // Auto-initialize
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-pui-pdf]').forEach(initPdfViewer);
  });

  window.PulsarPdfViewer = { init: initPdfViewer };
})();
