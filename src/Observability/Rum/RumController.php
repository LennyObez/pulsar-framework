<?php

declare(strict_types=1);

namespace Pulsar\Observability\Rum;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * HTTP controller for the /api/rum/collect endpoint.
 *
 * Receives POST requests with batched RUM metrics from the frontend
 * and delegates to RumCollector for processing.
 */
#[Internal]
final readonly class RumController
{
    public function __construct(
        private RumCollector $collector,
    ) {}

    /**
     * Handle POST /api/rum/collect.
     *
     * Accepts JSON payload: { "metrics": [...] }
     * Returns 204 on success or 400 on malformed input.
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();

        if ($body === '') {
            return Response::json(['error' => 'Empty body'], 400);
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        $result = $this->collector->collect($payload);

        return Response::json([
            'accepted' => $result->accepted,
            'rejected' => $result->rejected,
        ]);
    }
}
