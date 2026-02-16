<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Execution;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Exception for GraphQL parsing and execution errors.
 */
#[Api(since: '1.0.0')]
final class GraphqlException extends RuntimeException
{
    public static function syntaxError(string $message): self
    {
        return new self("GraphQL syntax error: $message");
    }

    public static function validationError(string $message): self
    {
        return new self("GraphQL validation error: $message");
    }

    public static function executionError(string $message): self
    {
        return new self("GraphQL execution error: $message");
    }

    public static function queryTooComplex(string $message): self
    {
        return new self("GraphQL query too complex: $message");
    }
}
