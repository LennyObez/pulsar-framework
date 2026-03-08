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
use Pulsar\Security\Crypto\HmacInterface;
use SensitiveParameter;

use function str_starts_with;
use function substr;

/**
 * Optional API key authentication for the CMS content API.
 *
 * Checks X-Api-Key header (preferred) or Authorization: Bearer.
 * If a key is present it must be valid; if absent, behavior depends
 * on the apiKeyRequired config flag.
 *
 * Storage hashing: API keys are stored as a keyed BLAKE2b digest
 * (HmacInterface). The pepper is injected at construction time and
 * derived from the master key via the KDF subkey for the cms.api_key
 * domain. SHA-256 was used in earlier RC builds (MED-5); switching to
 * a keyed hash means a hash leak alone is insufficient to brute-force
 * raw keys, even though API keys themselves carry 256 bits of entropy.
 */
#[Internal(reason: 'CMS API key middleware; implementation detail')]
final readonly class CmsApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ApiKeyRepositoryInterface $repository,
        private CmsConfig $config,
        private HmacInterface $hmac,
        #[SensitiveParameter]
        private string $hmacKey,
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

        $keyHash = $this->hmac->computeHex($apiKey, $this->hmacKey);
        $storedKey = $this->repository->findByKeyHash($keyHash);

        if ($storedKey === null || !$storedKey->isActive || $storedKey->isExpired()) {
            return Response::json(['error' => 'Invalid API key'], 401);
        }

        $this->repository->recordUsage($storedKey->id);

        $request = $request->withAttribute('cms_api_key', $storedKey);

        return $handler->handle($request);
    }

    /**
     * Extract the API key from the request.
     *
     * API keys are only accepted from the `X-Api-Key` header or the
     * `Authorization: Bearer ...` header. Query string fallback is
     * deliberately not supported: query parameters are recorded in
     * web-server access logs, browser history, Referer headers, proxy
     * caches, and application query loggers. Exposing a long-lived
     * rotating API key through any of those channels defeats the
     * rotation guarantee that makes key-based auth safe.
     */
    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('X-Api-Key');

        if ($header !== '') {
            return $header;
        }

        // Accept `Authorization: Bearer <key>` as an alternative header,
        // since API clients and CI tools frequently default to this scheme.
        $auth = $request->getHeaderLine('Authorization');

        if ($auth !== '' && str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);

            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }
}
