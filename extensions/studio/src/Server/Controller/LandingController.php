<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Http\Message\Response;

use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_QUOTES;

/**
 * Handles GET /studio — the Studio landing page.
 */
#[Internal]
final readonly class LandingController
{
    public function __construct(
        private StudioConfig $config,
        private ?EventStoreInterface $store = null,
    ) {}

    public function handle(ServerRequestInterface $_request): Response
    {
        $eventCount = $this->store?->count() ?? 0;
        $sizeBytes = $this->store?->sizeInBytes() ?? 0;
        $samplingPct = sprintf('%.0f%%', $this->config->samplingRate * 100.0);
        $retentionDays = $this->config->retention->maxAgeDays;
        $maxSizeMb = $this->config->retention->maxSizeMb;
        $collectors = $this->buildCollectorBadges();
        $storagePath = htmlspecialchars($this->config->storagePath, ENT_QUOTES, 'UTF-8');
        $formattedCount = $this->formatNumber($eventCount);
        $formattedSize = $this->formatBytes($sizeBytes);

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <nav class="studio-nav">
                    <a href="/studio" class="nav-brand">Pulsar Studio</a>
                    <div class="nav-links">
                        <a href="/studio/console">Console</a>
                        <a href="/studio/console/requests">Requests</a>
                        <a href="/studio/console/database">Database</a>
                        <a href="/studio/console/logs">Logs</a>
                        <a href="/studio/console/exceptions">Exceptions</a>
                        <a href="/studio/console/benchmarks">Benchmarks</a>
                    </div>
                </nav>
                <div class="dashboard">
                    <div class="metrics-row">
                        <div class="metric-card">
                            <div class="metric-label">Events Recorded</div>
                            <div class="metric-value">$formattedCount</div>
                            <div class="metric-subtitle">Total events in store</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label">Storage Used</div>
                            <div class="metric-value">$formattedSize</div>
                            <div class="metric-subtitle">Limit: $maxSizeMb MB</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label">Sampling Rate</div>
                            <div class="metric-value">$samplingPct</div>
                            <div class="metric-subtitle">Events captured</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label">Retention</div>
                            <div class="metric-value">{$retentionDays}d</div>
                            <div class="metric-subtitle">Max age before pruning</div>
                        </div>
                    </div>
                    <div class="card">
                        <h3>Explorers</h3>
                        <div class="landing-nav-grid">
                            <a href="/studio/console" class="landing-nav-card">
                                <span class="card-title">Console Overview</span>
                                <span class="card-desc">Throughput, latency percentiles, error rates, and slow routes at a glance.</span>
                            </a>
                            <a href="/studio/console/requests" class="landing-nav-card">
                                <span class="card-title">HTTP Requests</span>
                                <span class="card-desc">Inspect individual requests with method, path, status, and timing.</span>
                            </a>
                            <a href="/studio/console/database" class="landing-nav-card">
                                <span class="card-title">Database Queries</span>
                                <span class="card-desc">Analyze SQL queries, execution times, and query patterns.</span>
                            </a>
                            <a href="/studio/console/logs" class="landing-nav-card">
                                <span class="card-title">Logs</span>
                                <span class="card-desc">Browse structured log entries across all levels and channels.</span>
                            </a>
                            <a href="/studio/console/exceptions" class="landing-nav-card">
                                <span class="card-title">Exceptions</span>
                                <span class="card-desc">Track exceptions with stack traces, grouping, and occurrence counts.</span>
                            </a>
                            <a href="/studio/console/benchmarks" class="landing-nav-card">
                                <span class="card-title">Benchmarks</span>
                                <span class="card-desc">Run performance benchmarks and compare profiles across configurations.</span>
                            </a>
                        </div>
                    </div>
                    <div class="card">
                        <h3>Configuration</h3>
                        <table class="config-table">
                            <tr>
                                <td>Storage</td>
                                <td><code>$storagePath</code></td>
                            </tr>
                            <tr>
                                <td>Sampling</td>
                                <td>$samplingPct of events</td>
                            </tr>
                            <tr>
                                <td>Retention</td>
                                <td>$retentionDays days / $maxSizeMb MB max</td>
                            </tr>
                            <tr>
                                <td>Collectors</td>
                                <td><div class="collector-badges">$collectors</div></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return sprintf('%.1f KB', (float) $bytes / 1024.0);
        }

        return sprintf('%.1f MB', (float) $bytes / 1048576.0);
    }

    private function formatNumber(int $count): string
    {
        if ($count < 1000) {
            return (string) $count;
        }
        if ($count < 1000000) {
            return sprintf('%.1fK', (float) $count / 1000.0);
        }

        return sprintf('%.1fM', (float) $count / 1000000.0);
    }

    private function buildCollectorBadges(): string
    {
        $collectors = $this->config->collectors;
        $badges = [];

        $map = [
            'HTTP' => $collectors->http,
            'Database' => $collectors->database,
            'Logs' => $collectors->logs,
            'Exceptions' => $collectors->exceptions,
            'Scheduler' => $collectors->scheduler,
            'Flags' => $collectors->featureFlags,
        ];

        foreach ($map as $label => $enabled) {
            $class = $enabled ? 'collector-badge' : 'collector-badge disabled';
            $badges[] = sprintf('<span class="%s">%s</span>', $class, $label);
        }

        return implode('', $badges);
    }
}
