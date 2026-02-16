<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function bin2hex;
use function hash_equals;
use function in_array;
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
        private AdminConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        if ($this->isMutation($method)) {
            $token = $request->getHeaderLine('X-CSRF-Token');
            $sessionToken = $request->getAttribute('csrf_token');

            if ($token === '' || !is_string($sessionToken)) {
                return Response::json(
                    ['error' => 'CSRF token missing'],
                    ResponseStatus::Forbidden->value,
                );
            }

            if (!hash_equals($sessionToken, $token)) {
                return Response::json(
                    ['error' => 'CSRF token mismatch'],
                    ResponseStatus::Forbidden->value,
                );
            }
        }

        $response = $handler->handle($request);

        if ($this->config->security->csrfRotation && $this->isMutation($method)) {
            $newToken = bin2hex(random_bytes(32));
            $response = $response->withHeader('X-CSRF-Token', $newToken);
        }

        return $response;
    }

    private function isMutation(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
