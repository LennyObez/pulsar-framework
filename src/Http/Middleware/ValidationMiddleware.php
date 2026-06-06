<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Validator;

use function is_array;

/**
 * Abstract middleware that validates request data before passing to the next handler.
 *
 * Subclasses define the validation rules. When validation fails, a ValidationException
 * is thrown (to be caught by the ExceptionHandler for a 422 JSON response).
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
    abstract protected function rules(ServerRequestInterface $request): array;

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $data = $this->extractInputData($request);
        $this->validator->validateOrFail($data, $this->rules($request));

        return $handler->handle($request);
    }

    /**
     * Extract all input data from the request (query + parsed body).
     *
     * @return array<string, mixed>
     */
    private function extractInputData(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        /** @var array<string, mixed> $post */
        $post = is_array($parsedBody) ? $parsedBody : [];

        /** @var array<string, mixed> */
        return [...$query, ...$post];
    }
}
