<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function explode;
use function flush;
use function is_int;
use function is_string;
use function json_encode;
use function ob_end_flush;
use function sprintf;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * JSON API and SSE live endpoint for Studio.
 */
#[Internal]
final readonly class ApiController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private EventStoreInterface $store,
    ) {}

    /**
     * GET /studio/api/events: paginated JSON event listing.
     */
    public function events(ServerRequestInterface $request): Response
    {
        $filters = [];

        /** @var mixed $types */
        $types = $request->getAttribute('_query_types');
        if (is_string($types)) {
            $filters['event_type'] = explode(',', $types);
        }

        /** @var mixed $requestId */
        $requestId = $request->getAttribute('_query_request_id');
        if (is_string($requestId)) {
            $filters['request_id'] = $requestId;
        }

        /** @var mixed $sinceUs */
        $sinceUs = $request->getAttribute('_query_since');
        if (is_int($sinceUs) || is_string($sinceUs)) {
            $filters['since_us'] = (int) $sinceUs;
        }

        /** @var mixed $sinceId */
        $sinceId = $request->getAttribute('_query_since_id');
        if (is_int($sinceId) || is_string($sinceId)) {
            $filters['since_id'] = (int) $sinceId;
        }

        /** @var mixed $limitAttr */
        $limitAttr = $request->getAttribute('_query_limit');
        $limit = (is_int($limitAttr) || is_string($limitAttr)) ? (int) $limitAttr : 50;
        /** @var mixed $offsetAttr */
        $offsetAttr = $request->getAttribute('_query_offset');
        $offset = (is_int($offsetAttr) || is_string($offsetAttr)) ? (int) $offsetAttr : 0;

        $events = $this->store->query($filters, $limit, $offset);
        $total = $this->store->count($filters);

        return Response::json([
            'events' => $events,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * GET /studio/api/live: Server-Sent Events stream.
     *
     * @throws JsonException
     */
    public function live(ServerRequestInterface $request): Response
    {
        $filters = [];

        /** @var mixed $types */
        $types = $request->getAttribute('_query_types');
        if (is_string($types)) {
            $filters['event_type'] = explode(',', $types);
        }

        $lastEventId = $request->getHeaderLine('Last-Event-ID');
        $lastId = $lastEventId !== '' ? (int) $lastEventId : 0;

        // For SSE, we need to stream directly
        // Return headers-only response; actual streaming happens via output buffer
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        while (true) {
            $pollFilters = $filters;
            if ($lastId > 0) {
                $pollFilters['since_id'] = $lastId;
            }

            $events = $this->store->query($pollFilters, limit: 50);

            foreach ($events as $event) {
                /** @var int $eventId */
                $eventId = $event['id'] ?? 0;
                $data = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                echo sprintf("id: %d\ndata: %s\n\n", $eventId, $data);
                flush();

                if ($eventId > $lastId) {
                    $lastId = $eventId;
                }
            }

            if (connection_aborted()) {
                break;
            }

            usleep(500_000);
        }

        return new Response(
            statusCode: ResponseStatus::OK->value,
            headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
            ],
        );
    }
}
