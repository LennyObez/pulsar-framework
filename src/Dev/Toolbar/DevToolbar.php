<?php

declare(strict_types=1);

namespace Pulsar\Dev\Toolbar;

use Pulsar\Api\Internal;

use function count;
use function htmlspecialchars;
use function number_format;
use function sprintf;

use const ENT_QUOTES;

/**
 * Renders the floating developer toolbar as an HTML/CSS/JS snippet.
 *
 * The toolbar displays at the bottom of the page in dev mode and shows
 * request metrics, database queries, cache stats, loaded templates,
 * and routing information. It is collapsible and remembers its state
 * via a cookie.
 */
#[Internal]
final readonly class DevToolbar
{
    /**
     * Render the toolbar HTML for injection into the page.
     */
    public function render(ToolbarData $data): string
    {
        $requestTime = $this->esc($data->formattedRequestTime());
        $memory = $this->esc($data->formattedMemory());
        $phpVersion = $this->esc($data->phpVersion);
        $queryCount = $data->queryCount();
        $queryTime = number_format($data->totalQueryTimeMs(), 1);
        $cacheHits = $data->cacheHits;
        $cacheMisses = $data->cacheMisses;
        $templateCount = count($data->loadedTemplates);
        $route = $this->esc($data->routeName ?? $data->routePattern ?? 'N/A');
        $controller = $this->esc($data->controller ?? 'N/A');

        $queriesHtml = $this->renderQueries($data);
        $templatesHtml = $this->renderTemplates($data);

        return <<<HTML
            <div id="pulsar-toolbar" class="pulsar-toolbar" data-collapsed="false">
                {$this->renderStyles()}
                <div class="pulsar-toolbar__bar">
                    <div class="pulsar-toolbar__items">
                        <span class="pulsar-toolbar__item" title="Request Time">{$requestTime}</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item" title="Peak Memory">{$memory}</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item" title="PHP Version">PHP {$phpVersion}</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item pulsar-toolbar__item--clickable" title="Database Queries" onclick="pulsarToolbarToggle('queries')">DB: {$queryCount} ({$queryTime} ms)</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item" title="Cache Hits/Misses">Cache: {$cacheHits}H / {$cacheMisses}M</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item pulsar-toolbar__item--clickable" title="Templates" onclick="pulsarToolbarToggle('templates')">TPL: {$templateCount}</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item" title="Route">{$route}</span>
                        <span class="pulsar-toolbar__sep"></span>
                        <span class="pulsar-toolbar__item" title="Controller">{$controller}</span>
                    </div>
                    <button class="pulsar-toolbar__toggle" onclick="pulsarToolbarCollapse()" title="Toggle Toolbar" aria-label="Toggle developer toolbar">_</button>
                </div>
                <div class="pulsar-toolbar__panel" id="pulsar-toolbar-queries" style="display:none;">
                    <h4>Database Queries ({$queryCount})</h4>
                    {$queriesHtml}
                </div>
                <div class="pulsar-toolbar__panel" id="pulsar-toolbar-templates" style="display:none;">
                    <h4>Loaded Templates ({$templateCount})</h4>
                    {$templatesHtml}
                </div>
                {$this->renderScript()}
            </div>
            HTML;
    }

    private function renderQueries(ToolbarData $data): string
    {
        if ($data->queries === []) {
            return '<p class="pulsar-toolbar__empty">No queries executed.</p>';
        }

        $html = '<table class="pulsar-toolbar__table"><thead><tr><th>#</th><th>SQL</th><th>Time</th></tr></thead><tbody>';

        foreach ($data->queries as $index => $query) {
            $sql = $this->esc($query['sql']);
            $time = number_format($query['time_ms'], 2);
            $slowClass = $query['time_ms'] > 100.0 ? ' class="pulsar-toolbar__slow"' : '';

            $html .= sprintf(
                '<tr%s><td>%d</td><td><code>%s</code></td><td>%s ms</td></tr>',
                $slowClass,
                $index + 1,
                $sql,
                $time,
            );
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function renderTemplates(ToolbarData $data): string
    {
        if ($data->loadedTemplates === []) {
            return '<p class="pulsar-toolbar__empty">No templates loaded.</p>';
        }

        $html = '<ul class="pulsar-toolbar__list">';

        foreach ($data->loadedTemplates as $template) {
            $html .= sprintf('<li>%s</li>', $this->esc($template));
        }

        $html .= '</ul>';

        return $html;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function renderStyles(): string
    {
        return <<<'CSS'
            <style>
                .pulsar-toolbar {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    z-index: 999999;
                    font-family: 'JetBrains Mono', 'Cascadia Code', 'Fira Code', monospace;
                    font-size: 12px;
                    line-height: 1.4;
                }

                .pulsar-toolbar__bar {
                    display: flex;
                    align-items: center;
                    background: #0f172a;
                    border-top: 2px solid #0039cb;
                    padding: 0 12px;
                    height: 32px;
                    color: #94a3b8;
                    gap: 4px;
                }

                .pulsar-toolbar__items {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    flex: 1;
                    overflow-x: auto;
                }

                .pulsar-toolbar__item { padding: 0 8px; white-space: nowrap; }
                .pulsar-toolbar__item--clickable { cursor: pointer; color: #7399e6; }
                .pulsar-toolbar__item--clickable:hover { color: #c5d4f5; }

                .pulsar-toolbar__sep {
                    width: 1px;
                    height: 16px;
                    background: #334155;
                    flex-shrink: 0;
                }

                .pulsar-toolbar__toggle {
                    background: none;
                    border: none;
                    color: #64748b;
                    cursor: pointer;
                    font-size: 14px;
                    padding: 4px 8px;
                    font-family: inherit;
                }

                .pulsar-toolbar__toggle:hover { color: #e2e8f0; }

                .pulsar-toolbar__panel {
                    background: #1e293b;
                    border-top: 1px solid #334155;
                    padding: 12px 16px;
                    max-height: 300px;
                    overflow-y: auto;
                    color: #cbd5e1;
                }

                .pulsar-toolbar__panel h4 {
                    color: #0039cb;
                    margin: 0 0 8px;
                    font-size: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }

                .pulsar-toolbar__table {
                    width: 100%;
                    border-collapse: collapse;
                }

                .pulsar-toolbar__table th {
                    text-align: left;
                    color: #64748b;
                    padding: 4px 8px;
                    border-bottom: 1px solid #334155;
                    font-weight: 600;
                }

                .pulsar-toolbar__table td {
                    padding: 4px 8px;
                    border-bottom: 1px solid #1e293b;
                }

                .pulsar-toolbar__table code {
                    color: #c5d4f5;
                    word-break: break-all;
                }

                .pulsar-toolbar__slow td { color: #f59e0b !important; }
                .pulsar-toolbar__slow code { color: #fbbf24 !important; }

                .pulsar-toolbar__list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }

                .pulsar-toolbar__list li {
                    padding: 2px 0;
                    color: #94a3b8;
                }

                .pulsar-toolbar__empty {
                    color: #475569;
                    font-style: italic;
                    margin: 0;
                }

                .pulsar-toolbar[data-collapsed="true"] .pulsar-toolbar__items { display: none; }
                .pulsar-toolbar[data-collapsed="true"] .pulsar-toolbar__panel { display: none !important; }
            </style>
            CSS;
    }

    private function renderScript(): string
    {
        return <<<'JS'
            <script>
                (function() {
                    var toolbar = document.getElementById('pulsar-toolbar');
                    if (!toolbar) return;

                    var collapsed = document.cookie.match(/pulsar_toolbar=collapsed/);
                    if (collapsed) {
                        toolbar.dataset.collapsed = 'true';
                    }
                })();

                function pulsarToolbarToggle(panel) {
                    var el = document.getElementById('pulsar-toolbar-' + panel);
                    if (!el) return;

                    var panels = document.querySelectorAll('.pulsar-toolbar__panel');
                    panels.forEach(function(p) {
                        if (p !== el) p.style.display = 'none';
                    });

                    el.style.display = el.style.display === 'none' ? 'block' : 'none';
                }

                function pulsarToolbarCollapse() {
                    var toolbar = document.getElementById('pulsar-toolbar');
                    if (!toolbar) return;

                    var isCollapsed = toolbar.dataset.collapsed === 'true';
                    toolbar.dataset.collapsed = isCollapsed ? 'false' : 'true';

                    document.cookie = isCollapsed
                        ? 'pulsar_toolbar=; path=/; max-age=0; SameSite=Strict'
                        : 'pulsar_toolbar=collapsed; path=/; max-age=31536000; SameSite=Strict';
                }
            </script>
            JS;
    }
}
