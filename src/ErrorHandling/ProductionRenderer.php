<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Override;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Throwable;

use function htmlspecialchars;
use function sprintf;

/**
 * Safe production error renderer.
 *
 * Never exposes exception class names, stack traces, or file paths.
 * Shows only generic, user-safe error messages per status category.
 */
final class ProductionRenderer implements ExceptionRendererInterface
{
    #[Override]
    public function render(Throwable $exception, Request $request, ResponseStatus $status): string
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
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
