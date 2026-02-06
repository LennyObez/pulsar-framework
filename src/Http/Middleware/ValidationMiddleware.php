<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Validator;

/**
 * Abstract middleware that validates request data before passing to the next handler.
 *
 * Subclasses define the validation rules. When validation fails, a ValidationException
 * is thrown (to be caught by the ExceptionHandler for a 422 JSON response).
 */
abstract class ValidationMiddleware implements MiddlewareInterface
{
    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();
    }

    /**
     * Define the validation rules for this middleware.
     *
     * @return array<string, list<RuleInterface>>
     */
    abstract protected function rules(Request $request): array;

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        $result = $this->validator->validateOrFail($request->all(), $this->rules($request));

        return $next($request);
    }
}
