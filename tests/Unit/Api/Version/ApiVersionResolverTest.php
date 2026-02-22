<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Version\ApiVersion;
use Pulsar\Api\Version\ApiVersionResolver;
use Pulsar\Api\Version\VersionStrategy;

#[CoversClass(ApiVersionResolver::class)]
#[CoversClass(ApiVersion::class)]
final class ApiVersionResolverTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $queryParams
     */
    private function createRequest(
        string $path = '/',
        array $headers = [],
        array $queryParams = [],
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaderLine')
            ->willReturnCallback(static fn(string $name): string => $headers[$name] ?? '');

        $capturedAttributes = [];
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedAttributes) {
                $capturedAttributes[$name] = $value;
                return $request;
            });
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name): mixed => $capturedAttributes[$name] ?? null);

        return $request;
    }

    #[Test]
    public function resolversVersionFromUrlPrefix(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::UrlPrefix,
            supportedVersions: ['1', '2'],
        );
        $request = $this->createRequest(path: '/api/v2/users');

        $capturedVersion = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/v2/users');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getQueryParams')->willReturn([]);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedVersion) {
                if ($name === ApiVersionResolver::VERSION_ATTRIBUTE) {
                    $capturedVersion = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $resolver->process($request, $handler);

        self::assertInstanceOf(ApiVersion::class, $capturedVersion);
        self::assertSame('2', $capturedVersion->version);
    }

    #[Test]
    public function resolversVersionFromHeader(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::Header,
            supportedVersions: ['1', '2'],
        );

        $capturedVersion = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturnCallback(static fn(string $name) => $name === 'Api-Version' ? '2' : '');
        $request->method('getQueryParams')->willReturn([]);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedVersion) {
                if ($name === ApiVersionResolver::VERSION_ATTRIBUTE) {
                    $capturedVersion = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $resolver->process($request, $handler);

        self::assertInstanceOf(ApiVersion::class, $capturedVersion);
        self::assertSame('2', $capturedVersion->version);
    }

    #[Test]
    public function resolversVersionFromQueryParameter(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::QueryParameter,
            supportedVersions: ['1', '2'],
        );

        $capturedVersion = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getQueryParams')->willReturn(['api-version' => '2']);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedVersion) {
                if ($name === ApiVersionResolver::VERSION_ATTRIBUTE) {
                    $capturedVersion = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $resolver->process($request, $handler);

        self::assertInstanceOf(ApiVersion::class, $capturedVersion);
        self::assertSame('2', $capturedVersion->version);
    }

    #[Test]
    public function defaultsToVersion1WhenNoneSpecified(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::Header,
            defaultVersion: '1',
            supportedVersions: ['1'],
        );

        $capturedVersion = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getQueryParams')->willReturn([]);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedVersion) {
                if ($name === ApiVersionResolver::VERSION_ATTRIBUTE) {
                    $capturedVersion = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $resolver->process($request, $handler);

        self::assertInstanceOf(ApiVersion::class, $capturedVersion);
        self::assertSame('1', $capturedVersion->version);
    }

    #[Test]
    public function unsupportedVersionThrows400(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::Header,
            supportedVersions: ['1', '2'],
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturnCallback(static fn(string $name) => $name === 'Api-Version' ? '99' : '');
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unsupported API version/');

        $resolver->process($request, $handler);
    }

    #[Test]
    public function stripsVPrefixFromHeaderValue(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::Header,
            supportedVersions: ['1'],
        );

        $capturedVersion = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturnCallback(static fn(string $name) => $name === 'Api-Version' ? 'v1' : '');
        $request->method('getQueryParams')->willReturn([]);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedVersion) {
                if ($name === ApiVersionResolver::VERSION_ATTRIBUTE) {
                    $capturedVersion = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $resolver->process($request, $handler);

        self::assertInstanceOf(ApiVersion::class, $capturedVersion);
        self::assertSame('1', $capturedVersion->version);
    }

    #[Test]
    public function apiVersionPrefixedFormat(): void
    {
        $version = ApiVersion::fromString('2');

        self::assertSame('v2', $version->prefixed());
    }

    #[Test]
    public function apiVersionFromStringNormalizesPrefix(): void
    {
        $version = ApiVersion::fromString('V3');

        self::assertSame('3', $version->version);
        self::assertFalse($version->deprecated);
    }

    #[Test]
    public function deprecatedVersionMarkedCorrectly(): void
    {
        $resolver = new ApiVersionResolver(
            strategy: VersionStrategy::Header,
            supportedVersions: ['1', '2'],
            deprecatedVersions: ['1'],
        );

        $capturedVersion = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturnCallback(static fn(string $name) => $name === 'Api-Version' ? '1' : '');
        $request->method('getQueryParams')->willReturn([]);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedVersion) {
                if ($name === ApiVersionResolver::VERSION_ATTRIBUTE) {
                    $capturedVersion = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $resolver->process($request, $handler);

        self::assertInstanceOf(ApiVersion::class, $capturedVersion);
        self::assertTrue($capturedVersion->deprecated);
    }
}
