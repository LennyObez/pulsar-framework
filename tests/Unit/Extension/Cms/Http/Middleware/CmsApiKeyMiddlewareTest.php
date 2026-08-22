<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Commerce\ApiKey;
use Pulsar\Extension\Cms\Commerce\ApiKeyRepositoryInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsApiKeyMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\HmacService;

use function json_decode;
use function str_repeat;

#[CoversClass(CmsApiKeyMiddleware::class)]
final class CmsApiKeyMiddlewareTest extends TestCase
{
    private ApiKeyRepositoryInterface&Stub $repository;
    private RequestHandlerInterface&Stub $handler;
    private HmacService $hmac;
    private string $hmacKey;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(ApiKeyRepositoryInterface::class);

        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn(Response::json(['ok' => true]));

        // BLAKE2b keyed-hash via the real production service. 32 bytes of
        // deterministic key material is enough to cover the (Hmac, key)
        // pair the middleware expects.
        $this->hmac = new HmacService();
        $this->hmacKey = str_repeat("\x42", 32);
    }

    #[Test]
    public function no_key_and_not_required_passes_through(): void
    {
        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(method: 'GET', uri: '/api/content');

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    #[Test]
    public function no_key_and_required_returns_401(): void
    {
        $config = new CmsConfig(apiKeyRequired: true);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(method: 'GET', uri: '/api/content');

        $response = $middleware->process($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('API key required', $body['error']);
    }

    #[Test]
    public function valid_key_via_header_passes_through_with_attribute(): void
    {
        $rawKey = 'test-api-key-secret-value';
        $keyHash = $this->hmac->computeHex($rawKey, $this->hmacKey);

        $storedKey = new ApiKey(
            id: '019577a0-0000-7000-8000-000000000001',
            tenantId: null,
            name: 'Test Key',
            keyHash: $keyHash,
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );

        $this->repository->method('findByKeyHash')->willReturn($storedKey);

        $capturedRequest = null;
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $request) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $request;

                return Response::json(['ok' => true]);
            },
        );

        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/content',
            headers: ['X-Api-Key' => $rawKey],
        );

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($capturedRequest);
        self::assertSame($storedKey, $capturedRequest->getAttribute('cms_api_key'));
    }

    #[Test]
    public function valid_key_via_query_param_passes_through(): void
    {
        $rawKey = 'query-param-key';
        $keyHash = $this->hmac->computeHex($rawKey, $this->hmacKey);

        $storedKey = new ApiKey(
            id: '019577a0-0000-7000-8000-000000000002',
            tenantId: null,
            name: 'Query Key',
            keyHash: $keyHash,
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );

        $this->repository->method('findByKeyHash')->willReturn($storedKey);

        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/content',
            queryParams: ['api_key' => $rawKey],
        );

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function invalid_key_returns_401(): void
    {
        $this->repository->method('findByKeyHash')->willReturn(null);

        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/content',
            headers: ['X-Api-Key' => 'nonexistent-key'],
        );

        $response = $middleware->process($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Invalid API key', $body['error']);
    }

    #[Test]
    public function inactive_key_returns_401(): void
    {
        $rawKey = 'inactive-key';
        $keyHash = $this->hmac->computeHex($rawKey, $this->hmacKey);

        $inactiveKey = new ApiKey(
            id: '019577a0-0000-7000-8000-000000000003',
            tenantId: null,
            name: 'Inactive Key',
            keyHash: $keyHash,
            lastUsedAt: null,
            isActive: false,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );

        $this->repository->method('findByKeyHash')->willReturn($inactiveKey);

        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/content',
            headers: ['X-Api-Key' => $rawKey],
        );

        $response = $middleware->process($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function expired_key_returns_401(): void
    {
        $rawKey = 'expired-key';
        $keyHash = $this->hmac->computeHex($rawKey, $this->hmacKey);

        $expiredKey = new ApiKey(
            id: '019577a0-0000-7000-8000-000000000004',
            tenantId: null,
            name: 'Expired Key',
            keyHash: $keyHash,
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable('-1 year'),
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        $this->repository->method('findByKeyHash')->willReturn($expiredKey);

        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/content',
            headers: ['X-Api-Key' => $rawKey],
        );

        $response = $middleware->process($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function header_takes_precedence_over_query_param(): void
    {
        $headerKey = 'header-key-value';
        $headerHash = $this->hmac->computeHex($headerKey, $this->hmacKey);

        $storedKey = new ApiKey(
            id: '019577a0-0000-7000-8000-000000000005',
            tenantId: null,
            name: 'Header Key',
            keyHash: $headerHash,
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );

        $this->repository->method('findByKeyHash')->willReturnCallback(
            static function (string $hash) use ($headerHash, $storedKey): ?ApiKey {
                return $hash === $headerHash ? $storedKey : null;
            },
        );

        $config = new CmsConfig(apiKeyRequired: false);
        $middleware = new CmsApiKeyMiddleware($this->repository, $config, $this->hmac, $this->hmacKey);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/content',
            headers: ['X-Api-Key' => $headerKey],
            queryParams: ['api_key' => 'different-query-key'],
        );

        $response = $middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
