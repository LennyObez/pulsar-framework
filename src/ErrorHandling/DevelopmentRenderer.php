<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Throwable;

use function htmlspecialchars;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * Detailed HTML error page for development.
 *
 * Shows exception class/message, full stack trace, request details, and
 * chained previous exceptions. All values are HTML-escaped. Inline CSS, no external deps.
 */
final readonly class DevelopmentRenderer implements ExceptionRendererInterface
{
    public function __construct(
        private SensitiveDataScrubber $scrubber = new SensitiveDataScrubber(),
    ) {}
    #[Override]
    public function render(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): string
    {
        $title = sprintf('%d %s', $status->value, $this->escape($status->reasonPhrase()));
        $exceptionClass = $this->escape($exception::class);
        $exceptionMessage = $this->escape($exception->getMessage());
        $file = $this->escape($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->escape($exception->getTraceAsString());

        $requestMethod = $this->escape($request->getMethod());
        $requestUri = $this->escape((string) $request->getUri());

        $headersHtml = $this->renderHeaders($request);
        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();
        // F4.7: scrub query params through the same allowlist used for
        // headers so a query like `?token=...` does not leak to the
        // error page when an operator accidentally enables APP_DEBUG in
        // production.
        $queryHtml = $this->renderArray($this->scrubber->scrub($queryParams));
        $previousHtml = $this->renderPreviousExceptions($exception);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>$title</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: system-ui, -apple-system, sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 2rem; }
                    .container { max-width: 960px; margin: 0 auto; }
                    .header { background: #c0392b; color: #fff; padding: 1.5rem; border-radius: 8px 8px 0 0; }
                    .header h1 { font-size: 1.25rem; font-weight: 600; }
                    .header .status { font-size: 0.875rem; opacity: 0.9; margin-top: 0.25rem; }
                    .section { background: #16213e; padding: 1.5rem; border-bottom: 1px solid #0f3460; }
                    .section:last-child { border-radius: 0 0 8px 8px; border-bottom: none; }
                    .section h2 { font-size: 1rem; color: #e94560; margin-bottom: 1rem; }
                    .meta { font-size: 0.875rem; color: #a0a0a0; }
                    pre { background: #0a0a1a; padding: 1rem; border-radius: 4px; overflow-x: auto; font-size: 0.8125rem; line-height: 1.6; white-space: pre-wrap; word-break: break-all; }
                    .label { color: #e94560; font-weight: 600; }
                    table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
                    td { padding: 0.375rem 0.75rem; border-bottom: 1px solid #0f3460; vertical-align: top; }
                    td:first-child { width: 30%; color: #e94560; font-weight: 500; }
                    .previous { margin-top: 1rem; padding: 1rem; background: #0a0a1a; border-radius: 4px; }
                    .previous h3 { font-size: 0.875rem; color: #e94560; margin-bottom: 0.5rem; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>$exceptionClass</h1>
                        <div class="status">$title</div>
                    </div>

                    <div class="section">
                        <h2>Exception</h2>
                        <p>$exceptionMessage</p>
                        <p class="meta" style="margin-top: 0.5rem;">$file:$line</p>
                    </div>

                    <div class="section">
                        <h2>Stack Trace</h2>
                        <pre>$trace</pre>
                    </div>

                    <div class="section">
                        <h2>Request</h2>
                        <table>
                            <tr><td class="label">Method</td><td>$requestMethod</td></tr>
                            <tr><td class="label">URI</td><td>$requestUri</td></tr>
                        </table>
                    </div>

                    <div class="section">
                        <h2>Headers</h2>
                        <table>$headersHtml</table>
                    </div>

                    <div class="section">
                        <h2>Query Parameters</h2>
                        <table>$queryHtml</table>
                    </div>

                    $previousHtml
                </div>
            </body>
            </html>
            HTML;
    }

    private function renderHeaders(ServerRequestInterface $request): string
    {
        $html = '';
        /** @var array<string, list<string>|string> $rawHeaders */
        $rawHeaders = $request->getHeaders();
        $headers = $this->scrubber->scrubHeaders($rawHeaders);

        foreach ($headers as $name => $values) {
            $escapedName = $this->escape($name);
            $escapedValue = $this->escape(
                is_array($values) ? implode(', ', $values) : $values,
            );
            $html .= "<tr><td>$escapedName</td><td>$escapedValue</td></tr>";
        }

        return $html !== '' ? $html : '<tr><td colspan="2">None</td></tr>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderArray(array $data): string
    {
        if ($data === []) {
            return '<tr><td colspan="2">None</td></tr>';
        }

        $html = '';

        foreach ($data as $key => $value) {
            $escapedKey = $this->escape($key);
            $escapedValue = $this->escape(is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''));
            $html .= "<tr><td>$escapedKey</td><td>$escapedValue</td></tr>";
        }

        return $html;
    }

    private function renderPreviousExceptions(Throwable $exception): string
    {
        $previous = $exception->getPrevious();

        if ($previous === null) {
            return '';
        }

        $html = '<div class="section"><h2>Previous Exceptions</h2>';
        $current = $previous;

        while ($current !== null) {
            $class = $this->escape($current::class);
            $message = $this->escape($current->getMessage());
            $file = $this->escape($current->getFile());
            $line = $current->getLine();
            $trace = $this->escape($current->getTraceAsString());

            $html .= <<<BLOCK
                <div class="previous">
                    <h3>$class</h3>
                    <p>$message</p>
                    <p class="meta">$file:$line</p>
                    <pre style="margin-top: 0.5rem;">$trace</pre>
                </div>
                BLOCK;

            $current = $current->getPrevious();
        }

        $html .= '</div>';

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value);
    }
}
