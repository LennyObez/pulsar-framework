<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Adapts pre-PSR-15 Pulsar middleware to the PSR-15 interface.
 *
 * Wraps callable-based middleware that uses the old signature:
 *   process(Request $request, callable $next): Response
 *
 * Logs deprecation warnings and will be removed in v2.0.
 *
 * @deprecated Use PSR-15 MiddlewareInterface directly. This adapter will be removed in v2.0.
 */
#[Api(since: '1.0.0-rc.11')]
final class LegacyMiddlewareAdapter implements MiddlewareInterface
{
    private bool $deprecationEmitted = false;

    /**
     * @param callable $legacyMiddleware The old-style middleware callable
     * @param bool $devMode Whether to also trigger E_USER_DEPRECATED
     */
    public function __construct(
        private readonly mixed $legacyMiddleware,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $devMode = false,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->deprecationEmitted) {
            $this->emitDeprecation();
            $this->deprecationEmitted = true;
        }

        $next = static fn(ServerRequestInterface $req): ResponseInterface => $handler->handle($req);

        /** @var ResponseInterface */
        return ($this->legacyMiddleware)($request, $next);
    }

    private function emitDeprecation(): void
    {
        $message = 'Legacy middleware adapter is deprecated. Migrate to PSR-15 MiddlewareInterface. '
            . 'This adapter will be removed in v2.0.';

        $this->logger?->warning($message);

        if ($this->devMode) {
            trigger_error($message, E_USER_DEPRECATED);
        }
    }
}
