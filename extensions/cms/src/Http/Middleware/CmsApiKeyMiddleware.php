<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\ApiKeyRepositoryInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function hash;
use function is_string;

/**
 * Optional API key authentication for the CMS content API.
 *
 * Checks X-Api-Key header (preferred) or api_key query parameter.
 * If a key is present it must be valid; if absent, behavior depends
 * on the apiKeyRequired config flag.
 */
#[Internal(reason: 'CMS API key middleware; implementation detail')]
final readonly class CmsApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ApiKeyRepositoryInterface $repository,
        private CmsConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKey = $this->extractApiKey($request);

        if ($apiKey === null) {
            if ($this->config->apiKeyRequired) {
                return Response::json(['error' => 'API key required'], 401);
            }

            return $handler->handle($request);
        }

        $keyHash = hash('sha256', $apiKey);
        $storedKey = $this->repository->findByKeyHash($keyHash);

        if ($storedKey === null || !$storedKey->isActive || $storedKey->isExpired()) {
            return Response::json(['error' => 'Invalid API key'], 401);
        }

        $this->repository->recordUsage($storedKey->id);

        $request = $request->withAttribute('cms_api_key', $storedKey);

        return $handler->handle($request);
    }

    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('X-Api-Key');

        if ($header !== '') {
            return $header;
        }

        $queryKey = $request->getQueryParams()['api_key'] ?? null;

        return is_string($queryKey) && $queryKey !== '' ? $queryKey : null;
    }
}
