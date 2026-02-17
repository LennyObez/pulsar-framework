<?php

declare(strict_types=1);

namespace Pulsar\Api\Version;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function in_array;
use function is_string;
use function ltrim;
use function preg_match;

/**
 * Middleware that resolves the API version from the request.
 *
 * Supports URL prefix (/api/v1/...), header (Api-Version: 1),
 * and query parameter (?api-version=1) strategies.
 *
 * Stores the resolved version on the request attribute 'pulsar.api.version'.
 * Adds deprecation warning header for deprecated versions.
 */
#[Api(since: '1.0.0')]
final readonly class ApiVersionResolver implements MiddlewareInterface
{
    /**
     * Request attribute key for the resolved API version.
     */
    public const string VERSION_ATTRIBUTE = 'pulsar.api.version';

    /**
     * @param VersionStrategy $strategy How to resolve the version
     * @param string $defaultVersion Default version when none specified
     * @param list<string> $supportedVersions List of supported version identifiers
     * @param list<string> $deprecatedVersions Versions that trigger deprecation warnings
     * @param string $headerName Custom header name for header strategy
     * @param string $queryParam Query parameter name for query strategy
     */
    public function __construct(
        private VersionStrategy $strategy,
        private string $defaultVersion = '1',
        private array $supportedVersions = ['1'],
        private array $deprecatedVersions = [],
        private string $headerName = 'Api-Version',
        private string $queryParam = 'api-version',
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rawVersion = $this->extractVersion($request);
        $version = $rawVersion !== null ? ApiVersion::fromString($rawVersion) : ApiVersion::fromString($this->defaultVersion);

        // Validate supported version
        if (!in_array($version->version, $this->supportedVersions, true)) {
            throw ApiException::unsupportedVersion($version->prefixed());
        }

        // Mark deprecated
        $deprecated = in_array($version->version, $this->deprecatedVersions, true);
        $resolvedVersion = new ApiVersion(version: $version->version, deprecated: $deprecated);

        $request = $request->withAttribute(self::VERSION_ATTRIBUTE, $resolvedVersion);

        $response = $handler->handle($request);

        // Add deprecation warning header
        if ($resolvedVersion->deprecated) {
            $response = $response->withHeader(
                'Sunset',
                'API version ' . $resolvedVersion->prefixed() . ' is deprecated',
            );
        }

        return $response;
    }

    /**
     * Extract the version from the request based on configured strategy.
     */
    private function extractVersion(ServerRequestInterface $request): ?string
    {
        return match ($this->strategy) {
            VersionStrategy::UrlPrefix => $this->fromUrlPrefix($request),
            VersionStrategy::Header => $this->fromHeader($request),
            VersionStrategy::QueryParameter => $this->fromQueryParam($request),
        };
    }

    /**
     * Extract version from URL path prefix (e.g. /api/v1/users).
     */
    private function fromUrlPrefix(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#/api/v(\d+)(?:/|$)#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract version from a custom header.
     */
    private function fromHeader(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine($this->headerName);

        if ($header === '') {
            return null;
        }

        return ltrim($header, 'vV');
    }

    /**
     * Extract version from a query parameter.
     */
    private function fromQueryParam(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        $value = $params[$this->queryParam] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return ltrim(is_string($value) ? $value : '', 'vV');
    }
}
