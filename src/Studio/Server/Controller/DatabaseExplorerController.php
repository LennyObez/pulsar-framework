<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server\Controller;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

/**
 * Handles GET /studio/console/database — database query explorer.
 */
#[Internal]
final class DatabaseExplorerController
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {}

    public function handle(Request $request): Response
    {
        $events = $this->store->query(
            ['event_type' => ['db.query']],
            limit: 100,
        );

        $hasEvents = $events !== [];

        if (!$hasEvents) {
            return Response::html($this->emptyState());
        }

        $data = json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Database Queries - Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <div id="app" data-page="database-explorer" data-payload='{$data}'></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }

    private function emptyState(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Database Queries - Pulsar Studio</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 2rem; background: #0f172a; color: #e2e8f0; }
                    .empty { max-width: 600px; margin: 4rem auto; text-align: center; }
                    .empty h2 { color: #94a3b8; }
                    .empty p { color: #64748b; }
                    a { color: #38bdf8; }
                </style>
            </head>
            <body>
                <div class="empty">
                    <h2>No Database Query Events</h2>
                    <p>No database query events have been recorded yet. Database instrumentation is active when the InstrumentedConnection decorator wraps your database connection.</p>
                    <p><a href="/studio/console">Back to Console Overview</a></p>
                </div>
            </body>
            </html>
            HTML;
    }
}
