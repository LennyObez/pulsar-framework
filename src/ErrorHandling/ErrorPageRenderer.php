<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Closure;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Http\ResponseStatus;
use Pulsar\View\Engine\TemplateEngineInterface;
use Throwable;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Production error page renderer that resolves Pulse templates per status code.
 *
 * Resolution order:
 *   1. Specific template: `errors/{code}` (e.g. `errors/404`)
 *   2. Category fallback: `errors/4xx` or `errors/5xx`
 *   3. Inline HTML fallback when the template engine is unavailable
 *
 * In development mode, delegates to the DevErrorPage for detailed output.
 * In production mode, never exposes exception details, stack traces, or file paths.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ErrorPageRenderer implements ExceptionRendererInterface
{
    /**
     * @param (Closure(): ?TemplateEngineInterface)|null $templateEngineResolver
     *        Lazily resolves the template engine at render time. Supplied by the
     *        composition root so the renderer does not depend on wiring order:
     *        the exception handler is wired early (so it can catch boot-time
     *        errors) before ViewWiring binds the engine, so eagerly capturing
     *        the engine at construction would always be null. Resolving on
     *        render (after boot and route loading) picks up the bound engine.
     */
    public function __construct(
        private ?TemplateEngineInterface $templateEngine = null,
        private ?ExceptionRendererInterface $devRenderer = null,
        private bool $debug = false,
        private ?Closure $templateEngineResolver = null,
    ) {}

    #[Override]
    public function render(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): string
    {
        // Development mode: delegate to the detailed dev renderer
        if ($this->debug && $this->devRenderer !== null) {
            return $this->devRenderer->render($exception, $request, $status);
        }

        $code = $status->value;
        $context = $this->buildContext($exception, $request, $status);

        // Resolve the template engine lazily: it may be bound only after this
        // renderer is constructed (ViewWiring runs after ExceptionHandlerWiring),
        // so eager capture at construction time would miss it.
        $engine = $this->resolveEngine();

        if ($engine !== null) {
            $template = $this->resolveTemplate($code, $engine);

            if ($template !== null) {
                try {
                    return $engine->render($template, $context);
                } catch (Throwable) {
                    // Template rendering failed, fall through to inline HTML
                }
            }
        }

        // Inline HTML fallback: works even when template engine is down
        return $this->renderInlineFallback($status);
    }

    /**
     * Resolve the best template name for the given status code.
     *
     * @param TemplateEngineInterface|null $engine The engine to probe; when null
     *        it is resolved lazily (see {@see resolveEngine()}).
     *
     * @return string|null Template name in dot-notation, or null if none exists
     */
    public function resolveTemplate(int $code, ?TemplateEngineInterface $engine = null): ?string
    {
        $engine ??= $this->resolveEngine();

        if ($engine === null) {
            return null;
        }

        // 1. Try specific template (e.g. "errors.404")
        $specific = sprintf('errors.%d', $code);
        if ($engine->exists($specific)) {
            return $specific;
        }

        // 2. Try category fallback (e.g. "errors.4xx")
        $category = $this->categoryTemplate($code);
        if ($engine->exists($category)) {
            return $category;
        }

        return null;
    }

    /**
     * Resolve the template engine, preferring an eagerly-injected instance and
     * falling back to the lazy resolver. Returns null when neither yields one.
     */
    private function resolveEngine(): ?TemplateEngineInterface
    {
        if ($this->templateEngine !== null) {
            return $this->templateEngine;
        }

        if ($this->templateEngineResolver !== null) {
            return ($this->templateEngineResolver)();
        }

        return null;
    }

    /**
     * Build the template context array from exception and request data.
     *
     * @return array<string, mixed>
     */
    public function buildContext(
        Throwable $exception,
        ServerRequestInterface $request,
        ResponseStatus $status,
    ): array {
        $context = [
            'status' => $status->value,
            'statusPhrase' => $status->reasonPhrase(),
            'message' => $this->safeMessage($exception, $status),
            'requestUrl' => $this->esc($request->getUri()->getPath()),
            'requestMethod' => $request->getMethod(),
            'retryAfter' => $this->extractRetryAfter($exception),
            'maintenanceMessage' => $this->extractMaintenanceMessage($exception),
            'estimatedReturn' => $this->extractEstimatedReturn($exception),
            'isServerError' => $status->isServerError(),
            'isClientError' => $status->isClientError(),
        ];

        // Exception details only in debug mode
        if ($this->debug) {
            $context['exception'] = $exception;
            $context['exceptionClass'] = $exception::class;
            $context['exceptionMessage'] = $exception->getMessage();
            $context['trace'] = $exception->getTraceAsString();
        }

        return $context;
    }

    /**
     * Determine the category fallback template name.
     */
    private function categoryTemplate(int $code): string
    {
        if ($code >= 400 && $code < 500) {
            return 'errors.4xx';
        }

        return 'errors.5xx';
    }

    /**
     * Return a safe user-facing message (never from exception in production).
     */
    private function safeMessage(Throwable $exception, ResponseStatus $status): string
    {
        if ($this->debug) {
            return $exception->getMessage();
        }

        // In production, use the HTTP reason phrase only
        return $status->reasonPhrase();
    }

    /**
     * Extract Retry-After value from HttpException headers.
     */
    private function extractRetryAfter(Throwable $exception): ?int
    {
        if (!$exception instanceof HttpExceptionInterface) {
            return null;
        }

        $headers = $exception->getHeaders();
        $retryAfter = $headers['Retry-After'] ?? null;

        if ($retryAfter === null) {
            return null;
        }

        $seconds = (int) $retryAfter;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Extract maintenance message from exception.
     */
    private function extractMaintenanceMessage(Throwable $exception): ?string
    {
        if ($exception instanceof HttpExceptionInterface) {
            $headers = $exception->getHeaders();

            return $headers['X-Maintenance-Message'] ?? null;
        }

        return null;
    }

    /**
     * Extract estimated return time from exception.
     */
    private function extractEstimatedReturn(Throwable $exception): ?string
    {
        if ($exception instanceof HttpExceptionInterface) {
            $headers = $exception->getHeaders();

            return $headers['X-Estimated-Return'] ?? null;
        }

        return null;
    }

    /**
     * HTML-escape a string.
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Inline HTML fallback when the template engine is completely unavailable.
     *
     * Produces a complete, valid, accessible HTML document with no external
     * dependencies. Uses the Pulsar design charter colors inline.
     */
    private function renderInlineFallback(ResponseStatus $status): string
    {
        $code = $status->value;
        $phrase = $this->esc($status->reasonPhrase());
        $isServer = $status->isServerError();
        $bgColor = $isServer ? '#0f172a' : '#f8fafc';
        $textColor = $isServer ? '#e2e8f0' : '#1e293b';
        $mutedColor = $isServer ? '#94a3b8' : '#64748b';
        $accentColor = '#0039cb';
        $linkHover = '#002da1';
        $codeColor = $isServer ? '#475569' : '#cbd5e1';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$code} {$phrase}</title>
                <style>
                    *{margin:0;padding:0;box-sizing:border-box}
                    body{font-family:'Overpass',system-ui,-apple-system,'Segoe UI',sans-serif;background:{$bgColor};color:{$textColor};display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
                    .e{text-align:center;max-width:480px}
                    .e__code{font-family:'Montserrat',system-ui,sans-serif;font-size:7rem;font-weight:800;line-height:1;color:{$codeColor};letter-spacing:-.04em}
                    .e__title{font-family:'Montserrat',system-ui,sans-serif;font-size:1.5rem;font-weight:700;margin:.75rem 0 .5rem}
                    .e__desc{color:{$mutedColor};line-height:1.6;margin-bottom:2rem}
                    .e__actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap}
                    .e__btn{display:inline-flex;align-items:center;gap:.5rem;padding:.625rem 1.25rem;border-radius:6px;font-size:.875rem;font-weight:600;text-decoration:none;transition:all .15s ease}
                    .e__btn--primary{background:{$accentColor};color:#fff;border:2px solid {$accentColor}}
                    .e__btn--primary:hover{background:{$linkHover};border-color:{$linkHover}}
                    .e__btn--secondary{background:transparent;color:{$accentColor};border:2px solid {$accentColor}}
                    .e__btn--secondary:hover{background:{$accentColor};color:#fff}
                    .e__btn:focus-visible{outline:3px solid {$accentColor};outline-offset:2px}
                </style>
            </head>
            <body>
                <main class="e" role="main" aria-labelledby="error-title">
                    <div class="e__code" aria-hidden="true">{$code}</div>
                    <h1 class="e__title" id="error-title">{$phrase}</h1>
                    <p class="e__desc">An error occurred while processing your request.</p>
                    <nav class="e__actions" aria-label="Error recovery options">
                        <a class="e__btn e__btn--primary" href="/">Return home</a>
                        <a class="e__btn e__btn--secondary" href="javascript:history.back()">Go back</a>
                    </nav>
                </main>
            </body>
            </html>
            HTML;
    }
}
