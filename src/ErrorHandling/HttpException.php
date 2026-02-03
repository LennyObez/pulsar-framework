<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Pulsar\Http\ResponseStatus;
use RuntimeException;
use Throwable;

/**
 * General HTTP exception with status code and optional headers.
 */
class HttpException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly ResponseStatus $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode->value, $previous);
    }

    public function getStatusCode(): ResponseStatus
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 404 Not Found.
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(ResponseStatus::NotFound, $message);
    }

    /**
     * 403 Forbidden.
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(ResponseStatus::Forbidden, $message);
    }

    /**
     * 400 Bad Request.
     */
    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self(ResponseStatus::BadRequest, $message);
    }

    /**
     * 503 Service Unavailable.
     */
    public static function serviceUnavailable(string $message = 'Service Unavailable'): self
    {
        return new self(ResponseStatus::ServiceUnavailable, $message);
    }
}
