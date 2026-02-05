<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function htmlspecialchars;
use function json_encode;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console/timeline/{id} — request/job timeline view.
 */
#[Internal]
final readonly class TimelineController
{
    public function __construct(
        private TimelineBuilder $timelineBuilder,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(Request $_request, string $correlationId): Response
    {
        $events = $this->timelineBuilder->forCorrelation(
            requestId: $correlationId,
            jobId: $correlationId,
        );

        if ($events === []) {
            return Response::json(
                ['error' => 'No events found for this correlation ID'],
                ResponseStatus::NotFound,
            );
        }

        $data = json_encode([
            'correlation_id' => $correlationId,
            'events' => $events,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $safePayload = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Timeline - Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <div id="app" data-page="timeline" data-payload="$safePayload"></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }
}
