<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Pulsar\Api\Api;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\ResponseStatus;

/**
 * Thrown when request validation fails.
 *
 * Carries the full ValidationResult so handlers can inspect individual
 * field violations. Always maps to HTTP 422 Unprocessable Entity.
 */
#[Api(since: '1.0.0')]
final class ValidationException extends HttpException
{
    public function __construct(
        private readonly ValidationResult $result,
        string $message = 'Validation Failed',
    ) {
        parent::__construct(ResponseStatus::UnprocessableEntity, $message);
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    /**
     * @return list<array{field: string, message: string, rule: string, code: string}>
     */
    public function violations(): array
    {
        return $this->result->toArray();
    }
}
