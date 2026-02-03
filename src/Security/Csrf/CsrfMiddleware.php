<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use function is_string;

use Pulsar\Config\CsrfConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * CSRF protection middleware.
 *
 * Validates CSRF tokens on state-changing HTTP methods (POST, PUT, PATCH, DELETE).
 * Safe methods (GET, HEAD, OPTIONS) pass through without validation.
 *
 * Token is extracted from either:
 * - The configured header (default: `X-CSRF-Token`)
 * - The configured POST field (default: `_csrf_token`)
 *
 * Returns a 403 Forbidden JSON response when validation fails.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CsrfTokenManagerInterface $tokenManager,
        private CsrfConfig $config,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if (!$this->config->enabled) {
            return $next($request);
        }

        // Safe methods do not require CSRF validation
        if ($request->method->isSafe()) {
            return $next($request);
        }

        $token = $this->extractToken($request);

        if ($token === null) {
            return self::forbiddenResponse('CSRF token is missing');
        }

        if (!$this->tokenManager->validate($token)) {
            return self::forbiddenResponse('CSRF token is invalid');
        }

        return $next($request);
    }

    /**
     * Extract the CSRF token from the request (header or POST field).
     */
    private function extractToken(Request $request): ?string
    {
        // Try header first
        $headerToken = $request->header($this->config->headerName);

        if ($headerToken !== null && $headerToken !== '') {
            return $headerToken;
        }

        // Fall back to POST field
        $fieldToken = $request->post($this->config->formFieldName);

        if (is_string($fieldToken) && $fieldToken !== '') {
            return $fieldToken;
        }

        return null;
    }

    /**
     * Create a 403 Forbidden JSON response.
     */
    private static function forbiddenResponse(string $message): Response
    {
        return Response::json(
            ['error' => 'Forbidden', 'message' => $message],
            ResponseStatus::Forbidden,
        );
    }
}
