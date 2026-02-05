<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Config\StudioConfig;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function sprintf;

/**
 * Handles GET /studio — the Studio landing page.
 */
#[Internal]
final class LandingController
{
    public function __construct(
        private readonly StudioConfig $config,
        private readonly ?EventStoreInterface $store = null,
    ) {}

    public function handle(Request $request): Response
    {
        $eventCount = $this->store?->count() ?? 0;
        $sizeBytes = $this->store?->sizeInBytes() ?? 0;

        $html = sprintf(
            <<<'HTML'
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Pulsar Studio</title>
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 2rem; background: #0f172a; color: #e2e8f0; }
                        .container { max-width: 800px; margin: 0 auto; }
                        h1 { font-size: 2rem; margin-bottom: 0.5rem; }
                        .subtitle { color: #94a3b8; margin-bottom: 2rem; }
                        .card { background: #1e293b; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; }
                        .card h2 { margin-top: 0; font-size: 1.1rem; color: #38bdf8; }
                        .stat { font-size: 1.5rem; font-weight: bold; }
                        .links { display: flex; gap: 1rem; flex-wrap: wrap; }
                        .links a { display: inline-block; padding: 0.75rem 1.5rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; }
                        .links a:hover { background: #2563eb; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <h1>Pulsar Studio</h1>
                        <p class="subtitle">Observability and debugging dashboard</p>
                        <div class="card">
                            <h2>Quick Stats</h2>
                            <p>Events recorded: <span class="stat">%d</span></p>
                            <p>Storage used: <span class="stat">%s</span></p>
                            <p>Sampling rate: <span class="stat">%.0f%%</span></p>
                        </div>
                        <div class="card">
                            <h2>Navigation</h2>
                            <div class="links">
                                <a href="/studio/console">Console Overview</a>
                                <a href="/studio/console/requests">HTTP Requests</a>
                                <a href="/studio/console/database">Database Queries</a>
                                <a href="/studio/console/logs">Logs</a>
                                <a href="/studio/console/exceptions">Exceptions</a>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
                HTML,
            $eventCount,
            $this->formatBytes($sizeBytes),
            $this->config->samplingRate * 100.0,
        );

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
}
