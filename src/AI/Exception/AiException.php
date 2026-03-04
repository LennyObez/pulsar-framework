<?php

declare(strict_types=1);

namespace Pulsar\AI\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for AI SDK errors.
 */
#[Api(since: '1.0.0')]
class AiException extends RuntimeException
{
    /**
     * The provider returned an API error.
     */
    public static function apiError(string $provider, string $message, int $statusCode = 0): self
    {
        return new self(
            sprintf('AI provider "%s" returned error: %s', $provider, $message),
            $statusCode,
        );
    }

    /**
     * Failed to connect to the provider API.
     */
    public static function connectionFailed(string $provider, string $url): self
    {
        return new self(
            sprintf('Failed to connect to AI provider "%s" at %s', $provider, $url),
        );
    }

    /**
     * The provider is not configured.
     */
    public static function providerNotConfigured(string $provider): self
    {
        return new self(
            sprintf('AI provider "%s" is not configured. Set API key in ai.providers.%s.api_key', $provider, $provider),
        );
    }

    /**
     * The requested capability is not supported by this model.
     */
    public static function unsupportedCapability(string $model, string $capability): self
    {
        return new self(
            sprintf('Model "%s" does not support %s', $model, $capability),
        );
    }

    /**
     * Invalid JSON response from the provider.
     */
    public static function invalidResponse(string $provider): self
    {
        return new self(
            sprintf('AI provider "%s" returned invalid JSON response', $provider),
        );
    }

    /**
     * SSRF protection blocked a request to a private/reserved network.
     */
    public static function ssrfBlocked(string $provider, string $url, string $reason): self
    {
        return new self(
            sprintf('SSRF protection blocked request from AI provider "%s" to %s: %s', $provider, $url, $reason),
        );
    }

    /**
     * An identifier (table name, column name) contained invalid characters.
     */
    public static function unsafeIdentifier(string $identifier): self
    {
        return new self(
            sprintf('SQL identifier "%s" contains invalid characters; only [a-zA-Z0-9_] are allowed', $identifier),
        );
    }
}
