<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Http;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Graphql\Execution\GraphqlExecutor;
use Pulsar\Extension\Graphql\Schema\Schema;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * HTTP controller for the GraphQL endpoint.
 *
 * POST /graphql — Execute a query (reads query + variables from JSON body)
 * GET  /graphql — Return simplified schema introspection
 */
#[Api(since: '1.0.0')]
final readonly class GraphqlController
{
    public function __construct(
        private GraphqlExecutor $executor,
        private Schema $schema,
    ) {}

    /**
     * Execute a GraphQL query from the request body.
     */
    public function execute(ServerRequestInterface $request): Response
    {
        $body = (string) $request->getBody();

        if ($body === '') {
            return Response::json([
                'data' => null,
                'errors' => [['message' => 'Request body is empty']],
            ], 400);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json([
                'data' => null,
                'errors' => [['message' => 'Invalid JSON in request body']],
            ], 400);
        }

        $query = $payload['query'] ?? null;

        if (!is_string($query) || $query === '') {
            return Response::json([
                'data' => null,
                'errors' => [['message' => 'Missing or empty "query" field']],
            ], 400);
        }

        /** @var array<string, string|int|float|bool|null> $variables */
        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];

        $result = $this->executor->execute($query, $variables);

        $status = $result['errors'] !== [] && $result['data'] === null ? 400 : 200;

        return Response::json($result, $status);
    }

    /**
     * Return the schema introspection result.
     */
    public function introspect(ServerRequestInterface $request): Response
    {
        return Response::json([
            'data' => $this->schema->toIntrospection(),
        ]);
    }
}
