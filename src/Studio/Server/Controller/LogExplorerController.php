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
 * Handles GET /studio/console/logs — log entry explorer.
 */
#[Internal]
final class LogExplorerController
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {}

    public function handle(Request $request): Response
    {
        $events = $this->store->query(
            ['event_type' => ['log.entry']],
            limit: 100,
        );

        $data = json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $safePayload = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Logs - Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <div id="app" data-page="log-explorer" data-payload="{$safePayload}"></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }
}
