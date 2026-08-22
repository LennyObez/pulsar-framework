<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console/timeline/{id}: request/job timeline view.
 */
#[Internal]
final readonly class TimelineController
{
    use RendersStudioView;

    public function __construct(
        private TimelineBuilder $timelineBuilder,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $_request, string $correlationId): Response
    {
        $events = $this->timelineBuilder->forCorrelation(
            requestId: $correlationId,
            jobId: $correlationId,
        );

        if ($events === []) {
            return Response::json(
                ['error' => 'No events found for this correlation ID'],
                ResponseStatus::NotFound->value,
            );
        }

        $dataJson = json_encode([
            'correlation_id' => $correlationId,
            'events' => $events,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Timeline - Pulsar Studio', 'console/timeline', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }
}
