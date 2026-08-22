<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Core\Version;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\MatchedRoute;
use Throwable;

use function array_slice;
use function count;
use function file;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function max;
use function min;
use function number_format;
use function sprintf;

use const FILE_IGNORE_NEW_LINES;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_OS_FAMILY;
use const PHP_RELEASE_VERSION;
use const PHP_SAPI;
use const PHP_VERSION;

/**
 * Beautiful, informative error page for development mode.
 *
 * Displays source code with the failing line highlighted, a full stack trace
 * with expandable frames, request details, route info, environment data, and
 * executed database queries. Sensitive values are always redacted.
 *
 * Uses the Pulsar design charter styling (dark theme with deep blue palette).
 */
#[Internal]
final readonly class DevErrorPage implements ExceptionRendererInterface
{
    private const int SOURCE_CONTEXT_LINES = 5;

    /**
     * @param list<array{sql: string, time_ms: float, bindings?: list<mixed>}> $executedQueries
     *   Database queries executed before the error occurred.
     */
    public function __construct(
        private SensitiveDataScrubber $scrubber = new SensitiveDataScrubber(),
        private array $executedQueries = [],
    ) {}

    #[Override]
    public function render(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): string
    {
        $title = sprintf('%d %s', $status->value, $this->esc($status->reasonPhrase()));
        $exceptionClass = $this->esc($exception::class);
        $exceptionMessage = $this->esc($exception->getMessage());
        $file = $exception->getFile();
        $line = $exception->getLine();
        $sourceHtml = $this->renderSourceCode($file, $line);
        $traceHtml = $this->renderStackTrace($exception);
        $requestHtml = $this->renderRequestInfo($request);
        $routeHtml = $this->renderRouteInfo($request);
        $envHtml = $this->renderEnvironment();
        $queryHtml = $this->renderQueries();
        $previousHtml = $this->renderPreviousExceptions($exception);
        $escapedFile = $this->esc($file);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$title} &mdash; Pulsar</title>
                {$this->renderStyles()}
            </head>
            <body>
                <div class="dev-error">
                    <header class="dev-error__header">
                        <div class="dev-error__badge">{$title}</div>
                        <h1 class="dev-error__class">{$exceptionClass}</h1>
                        <p class="dev-error__message">{$exceptionMessage}</p>
                        <p class="dev-error__location">{$escapedFile}:{$line}</p>
                    </header>

                    <section class="dev-error__section">
                        <h2 class="dev-error__section-title">Source Code</h2>
                        {$sourceHtml}
                    </section>

                    <section class="dev-error__section">
                        <h2 class="dev-error__section-title">Stack Trace</h2>
                        {$traceHtml}
                    </section>

                    <section class="dev-error__section">
                        <h2 class="dev-error__section-title">Request</h2>
                        {$requestHtml}
                    </section>

                    <section class="dev-error__section">
                        <h2 class="dev-error__section-title">Route</h2>
                        {$routeHtml}
                    </section>

                    <section class="dev-error__section">
                        <h2 class="dev-error__section-title">Database Queries</h2>
                        {$queryHtml}
                    </section>

                    <section class="dev-error__section">
                        <h2 class="dev-error__section-title">Environment</h2>
                        {$envHtml}
                    </section>

                    {$previousHtml}

                    <footer class="dev-error__footer">
                        Pulsar Framework {$this->esc(Version::full())} &mdash; PHP {$this->esc(PHP_VERSION)}
                    </footer>
                </div>

                {$this->renderScript()}
            </body>
            </html>
            HTML;
    }

    /**
     * Render the source code around the error line with syntax highlighting.
     */
    private function renderSourceCode(string $filePath, int $errorLine): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '<p class="dev-error__empty">Source file not readable.</p>';
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $lines === []) {
            return '<p class="dev-error__empty">Could not read source file.</p>';
        }

        $start = max(0, $errorLine - self::SOURCE_CONTEXT_LINES - 1);
        $end = min(count($lines) - 1, $errorLine + self::SOURCE_CONTEXT_LINES - 1);
        $contextLines = array_slice($lines, $start, $end - $start + 1, true);

        $html = '<div class="dev-error__source"><table class="dev-error__source-table">';

        foreach ($contextLines as $index => $lineContent) {
            $lineNumber = $index + 1;
            $isErrorLine = $lineNumber === $errorLine;
            $rowClass = $isErrorLine ? ' dev-error__source-line--error' : '';
            $escapedContent = $this->esc($lineContent);

            $html .= sprintf(
                '<tr class="dev-error__source-line%s">'
                . '<td class="dev-error__source-num">%d</td>'
                . '<td class="dev-error__source-code"><pre>%s</pre></td>'
                . '</tr>',
                $rowClass,
                $lineNumber,
                $escapedContent,
            );
        }

        $html .= '</table></div>';

        return $html;
    }

    /**
     * Render a full stack trace with expandable frames.
     */
    private function renderStackTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();

        if ($trace === []) {
            return '<p class="dev-error__empty">No stack trace available.</p>';
        }

        $html = '<div class="dev-error__trace">';

        foreach ($trace as $index => $frame) {
            $frameFile = $this->esc($frame['file'] ?? '[internal]');
            $frameLine = $frame['line'] ?? 0;
            $frameClass = $this->esc($frame['class'] ?? '');
            $frameFunction = $this->esc($frame['function'] ?? '');
            $frameType = $this->esc($frame['type'] ?? '');
            $caller = $frameClass !== '' ? $frameClass . $frameType . $frameFunction . '()' : $frameFunction . '()';

            $sourcePreview = '';
            $filePath = $frame['file'] ?? null;
            if (is_string($filePath) && ($frameLine > 0) && is_file($filePath) && is_readable($filePath)) {
                $sourcePreview = $this->renderSourceCode($filePath, $frameLine);
            }

            $html .= sprintf(
                '<div class="dev-error__frame" data-frame-index="%d">'
                . '<div class="dev-error__frame-header" onclick="toggleFrame(%d)">'
                . '<span class="dev-error__frame-index">#%d</span>'
                . '<span class="dev-error__frame-caller">%s</span>'
                . '<span class="dev-error__frame-location">%s:%d</span>'
                . '</div>'
                . '<div class="dev-error__frame-body" id="frame-%d" style="display:none;">%s</div>'
                . '</div>',
                $index,
                $index,
                $index,
                $caller,
                $frameFile,
                $frameLine,
                $index,
                $sourcePreview,
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render request information (method, URL, headers, body).
     */
    private function renderRequestInfo(ServerRequestInterface $request): string
    {
        $method = $this->esc($request->getMethod());
        $uri = $this->esc((string) $request->getUri());
        $protocol = $this->esc($request->getProtocolVersion());

        $html = '<table class="dev-error__table">';
        $html .= $this->tableRow('Method', $method);
        $html .= $this->tableRow('URL', $uri);
        $html .= $this->tableRow('Protocol', 'HTTP/' . $protocol);
        $html .= '</table>';

        // Headers (scrubbed)
        $html .= '<h3 class="dev-error__subtitle">Headers</h3>';
        $html .= '<table class="dev-error__table">';

        /** @var array<string, list<string>|string> $rawHeaders */
        $rawHeaders = $request->getHeaders();
        $scrubbedHeaders = $this->scrubber->scrubHeaders($rawHeaders);

        foreach ($scrubbedHeaders as $name => $values) {
            $val = is_array($values) ? implode(', ', $values) : $values;
            $html .= $this->tableRow($name, $this->esc($val));
        }

        if ($scrubbedHeaders === []) {
            $html .= '<tr><td colspan="2" class="dev-error__empty-cell">No headers</td></tr>';
        }

        $html .= '</table>';

        // Query parameters
        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();
        if ($queryParams !== []) {
            $html .= '<h3 class="dev-error__subtitle">Query Parameters</h3>';
            $html .= '<table class="dev-error__table">';
            /** @var mixed $value */
            foreach ($queryParams as $key => $value) {
                $html .= $this->tableRow(
                    $key,
                    $this->esc(is_string($value) ? $value : ''),
                );
            }
            $html .= '</table>';
        }

        return $html;
    }

    /**
     * Render matched route information.
     */
    private function renderRouteInfo(ServerRequestInterface $request): string
    {
        $matchedRoute = $request->getAttribute('_matched_route');

        if (!$matchedRoute instanceof MatchedRoute) {
            return '<p class="dev-error__empty">No route matched for this request.</p>';
        }

        $html = '<table class="dev-error__table">';
        $html .= $this->tableRow('Name', $this->esc($matchedRoute->getName() ?? '(unnamed)'));
        $html .= $this->tableRow('Pattern', $this->esc($matchedRoute->route->path));

        /** @var mixed $handler */
        $handler = $matchedRoute->getHandler();
        if (is_string($handler)) {
            $handlerStr = $handler;
        } elseif (is_array($handler)) {
            /** @var list<string> $handlerList */
            $handlerList = array_map(static fn(mixed $part): string => is_string($part) ? $part : '?', $handler);
            $handlerStr = implode('::', $handlerList);
        } else {
            $handlerStr = '(closure)';
        }
        $html .= $this->tableRow('Handler', $this->esc($handlerStr));

        $middleware = $matchedRoute->getMiddleware();
        $html .= $this->tableRow('Middleware', $middleware !== [] ? $this->esc(implode(', ', $middleware)) : '(none)');

        $params = $matchedRoute->parameters;
        if ($params !== []) {
            $html .= $this->tableRow('Parameters', $this->esc(implode(', ', array_map(
                static fn(string $k, string $v): string => $k . '=' . $v,
                array_keys($params),
                $params,
            ))));
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Render environment information.
     */
    private function renderEnvironment(): string
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        $html = '<table class="dev-error__table">';
        $html .= $this->tableRow('PHP Version', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION);
        $html .= $this->tableRow('SAPI', PHP_SAPI);
        $html .= $this->tableRow('OS', PHP_OS_FAMILY);
        $html .= $this->tableRow('Pulsar Version', Version::full());
        $html .= $this->tableRow('Loaded Extensions', $this->esc(implode(', ', $extensions)));
        $html .= $this->tableRow('Memory Usage', $this->formatBytes(memory_get_usage(true)));
        $html .= $this->tableRow('Peak Memory', $this->formatBytes(memory_get_peak_usage(true)));
        $html .= '</table>';

        return $html;
    }

    /**
     * Render executed database queries.
     */
    private function renderQueries(): string
    {
        if ($this->executedQueries === []) {
            return '<p class="dev-error__empty">No queries executed before the error.</p>';
        }

        $totalMs = 0.0;
        $html = '<div class="dev-error__queries">';
        $html .= sprintf('<p class="dev-error__query-summary">%d queries executed</p>', count($this->executedQueries));

        foreach ($this->executedQueries as $index => $query) {
            $sql = $this->esc($query['sql']);
            $timeMs = $query['time_ms'];
            $totalMs += $timeMs;
            $timeFormatted = number_format($timeMs, 2);
            $slowClass = $timeMs > 100.0 ? ' dev-error__query--slow' : '';

            $html .= sprintf(
                '<div class="dev-error__query%s">'
                . '<div class="dev-error__query-header">'
                . '<span class="dev-error__query-index">#%d</span>'
                . '<span class="dev-error__query-time">%s ms</span>'
                . '</div>'
                . '<pre class="dev-error__query-sql">%s</pre>'
                . '</div>',
                $slowClass,
                $index + 1,
                $timeFormatted,
                $sql,
            );
        }

        $html .= sprintf(
            '<p class="dev-error__query-total">Total: %s ms</p>',
            number_format($totalMs, 2),
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * Render previous (chained) exceptions.
     */
    private function renderPreviousExceptions(Throwable $exception): string
    {
        $previous = $exception->getPrevious();
        if ($previous === null) {
            return '';
        }

        $html = '<section class="dev-error__section">';
        $html .= '<h2 class="dev-error__section-title">Previous Exceptions</h2>';

        $current = $previous;
        $depth = 1;
        while ($current !== null) {
            $html .= sprintf(
                '<div class="dev-error__previous">'
                . '<h3 class="dev-error__previous-class">%d. %s</h3>'
                . '<p class="dev-error__previous-message">%s</p>'
                . '<p class="dev-error__previous-location">%s:%d</p>'
                . '</div>',
                $depth,
                $this->esc($current::class),
                $this->esc($current->getMessage()),
                $this->esc($current->getFile()),
                $current->getLine(),
            );

            $current = $current->getPrevious();
            $depth++;
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * Build an HTML table row.
     */
    private function tableRow(string $label, string $value): string
    {
        return sprintf(
            '<tr><td class="dev-error__label">%s</td><td>%s</td></tr>',
            $this->esc($label),
            $value,
        );
    }

    /**
     * HTML-escape a string.
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format bytes into a human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024.0 && $unit < count($units) - 1) {
            $size /= 1024.0;
            $unit++;
        }

        return number_format($size, 2) . ' ' . ($units[$unit] ?? 'B');
    }

    /**
     * Inline CSS styles using the Pulsar design charter dark theme.
     */
    private function renderStyles(): string
    {
        return <<<'CSS'
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }

                body {
                    font-family: 'Overpass', system-ui, -apple-system, sans-serif;
                    background: #0f172a;
                    color: #e2e8f0;
                    line-height: 1.6;
                }

                .dev-error { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }

                .dev-error__header {
                    background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 50%, #b91c1c 100%);
                    padding: 2rem;
                    border-radius: 12px 12px 0 0;
                    border-bottom: 3px solid #ef4444;
                }

                .dev-error__badge {
                    display: inline-block;
                    background: rgba(0,0,0,0.3);
                    color: #fca5a5;
                    padding: 0.25rem 0.75rem;
                    border-radius: 4px;
                    font-size: 0.8125rem;
                    font-weight: 600;
                    margin-bottom: 0.75rem;
                    font-family: 'JetBrains Mono', monospace;
                }

                .dev-error__class {
                    font-family: 'Montserrat', system-ui, sans-serif;
                    font-size: 1.5rem;
                    font-weight: 700;
                    color: #fff;
                    margin-bottom: 0.5rem;
                }

                .dev-error__message {
                    font-size: 1.125rem;
                    color: #fecaca;
                    word-break: break-word;
                }

                .dev-error__location {
                    margin-top: 0.5rem;
                    font-size: 0.8125rem;
                    color: #fca5a5;
                    font-family: 'JetBrains Mono', monospace;
                }

                .dev-error__section {
                    background: #1e293b;
                    padding: 1.5rem 2rem;
                    border-bottom: 1px solid #334155;
                }

                .dev-error__section:last-of-type { border-radius: 0 0 12px 12px; border-bottom: none; }

                .dev-error__section-title {
                    font-family: 'Montserrat', system-ui, sans-serif;
                    font-size: 1rem;
                    font-weight: 700;
                    color: #0039cb;
                    margin-bottom: 1rem;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }

                .dev-error__subtitle {
                    font-size: 0.875rem;
                    font-weight: 600;
                    color: #94a3b8;
                    margin: 1rem 0 0.5rem;
                }

                /* Source code viewer */
                .dev-error__source { border-radius: 8px; overflow: hidden; border: 1px solid #334155; }

                .dev-error__source-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-family: 'JetBrains Mono', monospace;
                    font-size: 0.8125rem;
                    line-height: 1.8;
                }

                .dev-error__source-line td { padding: 0 1rem; background: #0f172a; }

                .dev-error__source-num {
                    width: 4rem;
                    text-align: right;
                    color: #475569;
                    user-select: none;
                    border-right: 1px solid #334155;
                    padding-right: 0.75rem !important;
                }

                .dev-error__source-code pre {
                    margin: 0;
                    white-space: pre-wrap;
                    word-break: break-all;
                    font-family: inherit;
                    background: transparent;
                }

                .dev-error__source-line--error td {
                    background: #451a1a !important;
                    color: #fca5a5;
                }

                .dev-error__source-line--error .dev-error__source-num {
                    color: #ef4444;
                    font-weight: 700;
                    border-right-color: #ef4444;
                }

                /* Stack trace */
                .dev-error__trace { display: flex; flex-direction: column; gap: 2px; }

                .dev-error__frame {
                    background: #0f172a;
                    border-radius: 6px;
                    overflow: hidden;
                }

                .dev-error__frame-header {
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                    padding: 0.75rem 1rem;
                    cursor: pointer;
                    transition: background 0.15s;
                }

                .dev-error__frame-header:hover { background: #1a2744; }

                .dev-error__frame-index {
                    font-family: 'JetBrains Mono', monospace;
                    font-size: 0.75rem;
                    color: #475569;
                    min-width: 2rem;
                }

                .dev-error__frame-caller {
                    flex: 1;
                    font-family: 'JetBrains Mono', monospace;
                    font-size: 0.8125rem;
                    color: #c5d4f5;
                }

                .dev-error__frame-location {
                    font-size: 0.75rem;
                    color: #64748b;
                    font-family: 'JetBrains Mono', monospace;
                }

                .dev-error__frame-body { padding: 0 1rem 1rem; }

                /* Table */
                .dev-error__table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 0.8125rem;
                }

                .dev-error__table td {
                    padding: 0.5rem 0.75rem;
                    border-bottom: 1px solid #334155;
                    vertical-align: top;
                    word-break: break-all;
                }

                .dev-error__label {
                    width: 180px;
                    color: #0039cb;
                    font-weight: 600;
                    font-family: 'JetBrains Mono', monospace;
                    font-size: 0.75rem;
                }

                /* Queries */
                .dev-error__query-summary { font-size: 0.875rem; color: #94a3b8; margin-bottom: 0.75rem; }
                .dev-error__query-total { font-size: 0.875rem; color: #94a3b8; margin-top: 0.75rem; font-weight: 600; }

                .dev-error__query {
                    background: #0f172a;
                    border-radius: 6px;
                    padding: 0.75rem 1rem;
                    margin-bottom: 0.5rem;
                    border-left: 3px solid #334155;
                }

                .dev-error__query--slow { border-left-color: #f59e0b; }

                .dev-error__query-header {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 0.5rem;
                    font-size: 0.75rem;
                    color: #64748b;
                }

                .dev-error__query-time { font-family: 'JetBrains Mono', monospace; }
                .dev-error__query--slow .dev-error__query-time { color: #f59e0b; }

                .dev-error__query-sql {
                    font-family: 'JetBrains Mono', monospace;
                    font-size: 0.8125rem;
                    color: #c5d4f5;
                    background: transparent;
                    white-space: pre-wrap;
                    word-break: break-all;
                    margin: 0;
                }

                /* Previous exceptions */
                .dev-error__previous {
                    background: #0f172a;
                    padding: 1rem;
                    border-radius: 6px;
                    margin-bottom: 0.5rem;
                }

                .dev-error__previous-class {
                    font-family: 'JetBrains Mono', monospace;
                    font-size: 0.875rem;
                    color: #fca5a5;
                    margin-bottom: 0.25rem;
                }

                .dev-error__previous-message { color: #cbd5e1; font-size: 0.875rem; }
                .dev-error__previous-location {
                    color: #64748b;
                    font-size: 0.75rem;
                    font-family: 'JetBrains Mono', monospace;
                    margin-top: 0.25rem;
                }

                .dev-error__empty { color: #64748b; font-style: italic; font-size: 0.875rem; }
                .dev-error__empty-cell { color: #64748b; font-style: italic; }

                .dev-error__footer {
                    text-align: center;
                    padding: 1.5rem;
                    font-size: 0.75rem;
                    color: #475569;
                }
            </style>
            CSS;
    }

    /**
     * Inline JavaScript for expandable stack trace frames.
     */
    private function renderScript(): string
    {
        return <<<'JS'
            <script>
                function toggleFrame(index) {
                    var el = document.getElementById('frame-' + index);
                    if (el) {
                        el.style.display = el.style.display === 'none' ? 'block' : 'none';
                    }
                }
            </script>
            JS;
    }
}
