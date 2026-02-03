<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server\Controller;

use const ENT_QUOTES;

use function htmlspecialchars;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Studio\Security\ProductionSafetyMode;

/**
 * Handles GET /studio/console/exceptions — exception explorer.
 */
#[Internal]
final readonly class ExceptionExplorerController
{
    public function __construct(
        private EventStoreInterface $store,
        private ProductionSafetyMode $safetyMode,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(Request $_request): Response
    {
        $events = $this->store->query(
            ['event_type' => ['exception']],
            limit: 100,
        );

        $data = json_encode([
            'events' => $events,
            'show_traces' => $this->safetyMode->allowStackTraces(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $safePayload = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Exceptions - Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <div id="app" data-page="exception-explorer" data-payload="$safePayload"></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }
}
