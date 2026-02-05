<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server\Controller;

use const ENT_QUOTES;

use function htmlspecialchars;
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
        $safePayload = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

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
                <div id="app" data-page="database-explorer" data-payload="{$safePayload}"></div>
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
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <nav class="studio-nav">
                    <a href="/studio" class="nav-brand">Pulsar Studio</a>
                    <div class="nav-links">
                        <a href="/studio/console">Console</a>
                        <a href="/studio/console/requests">Requests</a>
                        <a href="/studio/console/database" class="active">Database</a>
                        <a href="/studio/console/logs">Logs</a>
                        <a href="/studio/console/exceptions">Exceptions</a>
                    </div>
                </nav>
                <div class="dashboard">
                    <div class="empty-state">
                        <h2>No Database Query Events</h2>
                        <p>No database query events have been recorded yet. Database instrumentation is active when the InstrumentedConnection decorator wraps your database connection.</p>
                        <a href="/studio/console" class="btn">Back to Console Overview</a>
                    </div>
                </div>
            </body>
            </html>
            HTML;
    }
}
