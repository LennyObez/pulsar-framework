<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Internal;
use Pulsar\Config\DomainConfig;

use function is_string;
use function preg_replace;
use function rawurlencode;
use function str_replace;
use function trim;

/**
 * Builds a URL path (or domain-qualified URL) for a resolved route.
 *
 * Extracted from {@see Router::url()} so URL generation is a single
 * responsibility, separate from route registration and matching.
 */
#[Internal(reason: 'Route URL generation; use Router::url()')]
final class RouteUrlGenerator
{
    /**
     * Generate a URL for a resolved route.
     *
     * When a DomainConfig is provided and the route's attributes include a
     * 'scope' mapped to a subdomain, returns a fully-qualified URL; otherwise a
     * relative path.
     *
     * @param array<string, string> $parameters
     */
    public static function generate(Route $route, array $parameters, ?DomainConfig $domainConfig): string
    {
        $path = $route->path;

        if ($parameters !== []) {
            $search = [];
            $replace = [];

            foreach ($parameters as $key => $value) {
                // RFC 3986 §2 path-segment encoding: a raw value
                // containing `/`, `?`, `#`, ` `, or any reserved byte would
                // otherwise punch out of its segment and either change the route
                // taken or become a path-traversal vector against routes
                // downstream of this URL. `rawurlencode` percent-encodes every
                // reserved byte, so a parameter like `'../admin'` becomes
                // `..%2Fadmin`: the `/` is encoded (no segment breakout), while
                // the unreserved dots remain literal and harmless on their own.
                $encoded = rawurlencode($value);
                $search[] = '{' . $key . '}';
                $search[] = '{' . $key . '?}';
                $replace[] = $encoded;
                $replace[] = $encoded;
            }

            $path = str_replace($search, $replace, $path);
        }

        // Remove unfilled optional parameters
        $replaced = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\?}#', '', $path);
        $path = $replaced ?? $path;

        // Clean up double slashes
        $replaced = preg_replace('#//+#', '/', $path);
        $path = $replaced ?? $path;

        $relativePath = '/' . trim($path, '/');

        // Domain-aware URL generation: if a route has a scope attribute and that
        // scope is mapped to a subdomain, generate a fully-qualified URL.
        if ($domainConfig !== null && $domainConfig->hasSubdomainMappings()) {
            /** @var mixed $scope */
            $scope = $route->attributes['scope'] ?? null;

            if (is_string($scope)) {
                $subdomain = $domainConfig->subdomainForScope($scope);

                if ($subdomain !== null) {
                    return $domainConfig->scheme . '://' . $subdomain . '.' . $domainConfig->defaultDomain . $relativePath;
                }
            }
        }

        return $relativePath;
    }
}
