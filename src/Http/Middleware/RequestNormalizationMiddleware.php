<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;

use function preg_match;
use function rawurldecode;
use function str_contains;

/**
 * Rejects or normalizes malicious path traversal sequences in request URIs.
 *
 * Blocks requests containing:
 * - Path traversal sequences (../, ..\, %2e%2e/, etc.)
 * - Null bytes (%00)
 * - Double-encoded traversal patterns
 *
 * This middleware should be placed early in the pipeline, before the router.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequestNormalizationMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        // Check raw path for traversal patterns
        if (self::containsTraversal($path) || self::containsNullByte($path)) {
            return new Response(400);
        }

        // Check single-decoded path (catches %2e%2e)
        $decoded = rawurldecode($path);

        if (self::containsTraversal($decoded) || self::containsNullByte($decoded)) {
            return new Response(400);
        }

        // Check double-decoded path (catches %252e%252e double-encoding)
        $doubleDecoded = rawurldecode($decoded);

        if ($doubleDecoded !== $decoded && (self::containsTraversal($doubleDecoded) || self::containsNullByte($doubleDecoded))) {
            return new Response(400);
        }

        // Check query string for null bytes
        if ($query !== '' && self::containsNullByte($query)) {
            return new Response(400);
        }

        return $handler->handle($request);
    }

    /**
     * Check if a path contains directory traversal sequences.
     */
    private static function containsTraversal(string $path): bool
    {
        // Match ../ or ..\ (forward or backslash traversal)
        if (str_contains($path, '../') || str_contains($path, '..\\')) {
            return true;
        }

        // Match a bare .. at the end of the path (e.g. /foo/..)
        if (preg_match('#/\.\.$#', $path) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Check if a string contains null bytes (encoded or literal).
     */
    private static function containsNullByte(string $value): bool
    {
        return str_contains($value, "\0") || str_contains($value, '%00');
    }
}
