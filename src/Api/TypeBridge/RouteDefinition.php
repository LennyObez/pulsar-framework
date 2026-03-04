<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use NoDiscard;
use Pulsar\Api\Api;

use function count;
use function sprintf;

/**
 * Represents a route endpoint for TypeScript client generation.
 */
#[Api(since: '1.0.0')]
final readonly class RouteDefinition
{
    /**
     * @param string $name Route name (dot-separated path like 'users.show')
     * @param string $method HTTP method
     * @param string $path URL path pattern (e.g. '/users/{id}')
     * @param list<ParameterDefinition> $parameters Route/query parameters
     * @param string|null $requestType TypeScript type for request body
     * @param string $responseType TypeScript type for response
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public array $parameters = [],
        public ?string $requestType = null,
        public string $responseType = 'unknown',
    ) {}

    /**
     * Generate the TypeScript function signature for this route.
     */
    #[NoDiscard]
    public function toTypeScriptSignature(): string
    {
        $params = [];

        foreach ($this->parameters as $param) {
            $paramType = $param->optional
                ? $param->typeScriptType . ' | undefined'
                : $param->typeScriptType;
            $params[] = $param->name . ': ' . $paramType;
        }

        if ($this->requestType !== null) {
            $params[] = 'data: ' . $this->requestType;
        }

        $paramString = implode(', ', $params);

        return sprintf(
            '%s(%s): Promise<%s>',
            $this->clientMethodName(),
            $paramString,
            $this->responseType,
        );
    }

    /**
     * Generate the client method name from the route name.
     *
     * Converts dot-separated route names (users.show) to
     * camelCase method names (usersShow).
     */
    private function clientMethodName(): string
    {
        $parts = explode('.', $this->name);

        if (count($parts) === 1) {
            return $parts[0];
        }

        $first = array_shift($parts);
        $rest = array_map('ucfirst', $parts);

        return $first . implode('', $rest);
    }
}
