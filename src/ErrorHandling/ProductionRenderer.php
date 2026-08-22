<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;
use Throwable;

use function htmlspecialchars;
use function sprintf;

/**
 * Safe production error renderer.
 *
 * Never exposes exception class names, stack traces, or file paths.
 * Shows only generic, user-safe error messages per status category.
 *
 * Public because it is the single definition of the last-resort error page,
 * and the entry points that need one — the kernel, the micro-kernel, the
 * worker runtimes, the front controller's shutdown handler — live in four
 * different modules. The alternative was four copies of a security control,
 * which is how three of them end up out of date.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class ProductionRenderer implements ExceptionRendererInterface
{
    /**
     * Headers the last-resort page carries itself.
     *
     * {@see response()} is used where the middleware pipeline is not running —
     * before a request object exists, after a boot that died before
     * SecurityHeadersMiddleware was wired, or from the front controller's
     * shutdown handler. Nothing downstream will add headers there, so the
     * restrictive set travels with the page instead of being left to a pipeline
     * that will never see it. The page is self-contained: one inline `<style>`
     * block, no script and no external reference, which is why
     * `style-src 'unsafe-inline'` is the whole of what it needs.
     */
    private const array LAST_RESORT_HEADERS = [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    ];

    #[Override]
    public function render(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): string
    {
        return $this->renderStatus($status);
    }

    /**
     * A complete error response for a failure that never reached — or has
     * already left — the middleware pipeline.
     *
     * Takes no `Throwable` on purpose: the caller has already decided which
     * status the client is allowed to learn, and nothing about the failure
     * itself may influence the bytes that leave.
     */
    public function response(ResponseStatus $status): ResponseInterface
    {
        return new Response(
            statusCode: $status->value,
            headers: self::LAST_RESORT_HEADERS,
            body: $this->renderStatus($status),
        );
    }

    private function renderStatus(ResponseStatus $status): string
    {
        $statusCode = $status->value;
        $title = sprintf('%d %s', $statusCode, $this->escape($status->reasonPhrase()));
        $message = $this->genericMessage($status);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>$title</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: system-ui, -apple-system, sans-serif; background: #f8f9fa; color: #333; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                    .error { text-align: center; padding: 2rem; }
                    .error h1 { font-size: 3rem; font-weight: 700; color: #dee2e6; }
                    .error p { font-size: 1.125rem; margin-top: 1rem; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class="error">
                    <h1>$statusCode</h1>
                    <p>$message</p>
                </div>
            </body>
            </html>
            HTML;
    }

    private function genericMessage(ResponseStatus $status): string
    {
        if ($status === ResponseStatus::NotFound) {
            return 'The page you are looking for could not be found.';
        }

        if ($status === ResponseStatus::Forbidden) {
            return 'You do not have permission to access this resource.';
        }

        if ($status === ResponseStatus::MethodNotAllowed) {
            return 'The request method is not supported for this resource.';
        }

        // The body ceiling is server policy, not a secret, and a client that is
        // told only "could not be processed" cannot act on a 413.
        if ($status === ResponseStatus::PayloadTooLarge) {
            return 'The request body is larger than this server accepts.';
        }

        if ($status->isClientError()) {
            return 'The request could not be processed.';
        }

        if ($status->isServerError()) {
            return 'An internal error occurred. Please try again later.';
        }

        return 'An unexpected error occurred.';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value);
    }
}
