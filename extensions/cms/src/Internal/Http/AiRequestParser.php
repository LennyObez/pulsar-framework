<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Http;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_int;
use function is_string;

/**
 * Extracts and validates request data for AI API endpoints.
 *
 * Encapsulates the repeated parse/validate pattern used across all
 * AI controller endpoints to reduce boilerplate.
 */
#[Internal]
final readonly class AiRequestParser
{
    public function __construct(
        private CmsConfig $config,
    ) {}

    /**
     * Check if AI is enabled and parse the request body.
     *
     * @return array<string, mixed>|Response Parsed body array on success, or Response on failure
     */
    public function parse(ServerRequestInterface $request): array|Response
    {
        if (!$this->config->ai->enabled) {
            return Response::json(['error' => 'AI assistant is not enabled'], 403);
        }

        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * Extract a required string field, returning null if missing/empty.
     *
     * @param array<string, mixed> $body
     */
    public function requireString(array $body, string $field): ?string
    {
        $value = is_string($body[$field] ?? null) ? $body[$field] : null;

        return ($value !== null && $value !== '') ? $value : null;
    }

    /**
     * Extract an optional string field with a default.
     *
     * @param array<string, mixed> $body
     */
    public function optionalString(array $body, string $field, string $default = ''): string
    {
        return is_string($body[$field] ?? null) ? $body[$field] : $default;
    }

    /**
     * Extract an optional integer field with a default.
     *
     * @param array<string, mixed> $body
     */
    public function optionalInt(array $body, string $field, int $default): int
    {
        return is_int($body[$field] ?? null) ? $body[$field] : $default;
    }

    /**
     * Extract an optional array field.
     *
     * @param array<string, mixed> $body
     * @return list<string>|null
     */
    public function optionalStringArray(array $body, string $field): ?array
    {
        /** @var list<string>|null */
        return is_array($body[$field] ?? null) ? $body[$field] : null;
    }

    /**
     * Create a 422 response for a missing required field.
     */
    public function missingFieldResponse(string $field): Response
    {
        return Response::json(['error' => "Missing required field: {$field}"], 422);
    }
}
