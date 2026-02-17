<?php

declare(strict_types=1);

namespace Pulsar\Inertia;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * PSR-15 middleware for Inertia-style SPA bridge.
 *
 * Detects Inertia requests, manages asset versioning, handles
 * partial reloads, and injects shared data (auth user, flash
 * messages, CSRF token) into every Inertia response.
 */
#[Api(since: '1.0.0')]
final class InertiaMiddleware implements MiddlewareInterface
{
    /** @var array<string, mixed> */
    private array $sharedProps = [];

    public function __construct(
        private readonly InertiaConfig $config = new InertiaConfig(),
        private readonly string $assetVersion = '',
        private readonly ResponseFactoryInterface $responseFactory = new ResponseFactory(),
    ) {}

    /**
     * Share props with every Inertia response.
     *
     * @param array<string, mixed> $props
     */
    public function share(array $props): void
    {
        $this->sharedProps = array_merge($this->sharedProps, $props);
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isInertia = $request->hasHeader('X-Inertia');
        $request = $request
            ->withAttribute('inertia', $isInertia)
            ->withAttribute('inertia_shared_props', $this->sharedProps)
            ->withAttribute('inertia_version', $this->assetVersion);

        if ($isInertia && $this->assetVersion !== '') {
            $clientVersion = $request->getHeaderLine($this->config->versionHeader);

            if ($clientVersion !== '' && $clientVersion !== $this->assetVersion) {
                return $this->forceRefresh($request);
            }
        }

        $response = $handler->handle($request);

        if ($isInertia) {
            $response = $response->withAddedHeader('Vary', 'X-Inertia');
        }

        return $response;
    }

    /**
     * Force a full page refresh when asset versions mismatch.
     *
     * Returns 409 Conflict which the Inertia client handles by
     * performing a hard visit to the current URL.
     */
    private function forceRefresh(ServerRequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();

        return $this->responseFactory->createResponse(409)
            ->withHeader('X-Inertia-Location', $uri);
    }
}
