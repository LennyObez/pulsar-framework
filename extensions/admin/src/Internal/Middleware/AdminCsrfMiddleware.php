<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function bin2hex;
use function hash_equals;
use function is_string;
use function random_bytes;

/**
 * CSRF protection for admin mutation routes.
 *
 * Validates a CSRF token on POST/PUT/PATCH/DELETE requests.
 * When csrf_rotation is enabled, a fresh token is returned in the
 * X-CSRF-Token response header after each mutation.
 */
#[Internal]
final readonly class AdminCsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        if ($this->isMutation($request->method)) {
            $token = $request->header('X-CSRF-Token');
            $sessionToken = $request->attribute('csrf_token');

            if ($token === null || !is_string($sessionToken)) {
                return Response::json(
                    ['error' => 'CSRF token missing'],
                    ResponseStatus::Forbidden,
                );
            }

            if (!hash_equals($sessionToken, $token)) {
                return Response::json(
                    ['error' => 'CSRF token mismatch'],
                    ResponseStatus::Forbidden,
                );
            }
        }

        $response = $next($request);

        if ($this->config->security->csrfRotation && $this->isMutation($request->method)) {
            $newToken = bin2hex(random_bytes(32));
            $response = $response->withHeader('X-CSRF-Token', $newToken);
        }

        return $response;
    }

    private function isMutation(Method $method): bool
    {
        return match ($method) {
            Method::POST, Method::PUT, Method::PATCH, Method::DELETE => true,
            default => false,
        };
    }
}
