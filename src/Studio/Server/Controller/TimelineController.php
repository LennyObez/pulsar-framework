<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server\Controller;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Pulsar\Api\Internal;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Console\Aggregation\TimelineBuilder;

/**
 * Handles GET /studio/console/timeline/{id} — request/job timeline view.
 */
#[Internal]
final class TimelineController
{
    public function __construct(
        private readonly TimelineBuilder $timelineBuilder,
    ) {}

    public function handle(Request $request, string $correlationId): Response
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
                <div id="app" data-page="timeline" data-payload='{$data}'></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }
}
