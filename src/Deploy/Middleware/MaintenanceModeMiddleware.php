<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Middleware;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Deploy\MaintenanceMode;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * PSR-15 middleware that returns 503 when maintenance mode is active.
 *
 * Allows bypass via:
 * - A secret passed as a query parameter or cookie
 * - An allowed IP address
 */
#[Internal]
final readonly class MaintenanceModeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MaintenanceMode $maintenanceMode,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->maintenanceMode->isActive()) {
            return $handler->handle($request);
        }

        $payload = $this->maintenanceMode->payload();

        if ($payload === null) {
            return $handler->handle($request);
        }

        // Check IP bypass
        $serverParams = $request->getServerParams();
        $clientIp = $serverParams['REMOTE_ADDR'] ?? null;

        if (is_string($clientIp) && $clientIp !== '' && $this->maintenanceMode->isIpAllowed($clientIp)) {
            return $handler->handle($request);
        }

        // Check secret bypass via query parameter
        $queryParams = $request->getQueryParams();
        $querySecret = $queryParams['maintenance_secret'] ?? null;

        if (is_string($querySecret) && $this->maintenanceMode->checkSecret($querySecret)) {
            return $handler->handle($request);
        }

        // Check secret bypass via cookie
        $cookies = $request->getCookieParams();
        $cookieSecret = $cookies['maintenance_secret'] ?? null;

        if (is_string($cookieSecret) && $this->maintenanceMode->checkSecret($cookieSecret)) {
            return $handler->handle($request);
        }

        // Return 503 Service Unavailable
        $body = json_encode(
            ['message' => $payload['message'], 'retry_after' => $payload['retry_after']],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $stream = $this->streamFactory->createStream($body);

        return $this->responseFactory->createResponse(503, 'Service Unavailable')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Retry-After', (string) $payload['retry_after'])
            ->withBody($stream);
    }
}
