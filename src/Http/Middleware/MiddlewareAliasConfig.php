<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Auth\Middleware\AuthenticationRateLimitMiddleware;
use Pulsar\Security\Csrf\CsrfMiddleware;

/**
 * Provides default middleware alias and group configurations.
 *
 * Registers common short names ('auth', 'csrf', 'rate-limit', etc.)
 * and groups ('web', 'api') with the MiddlewareRegistry.
 * @api
 */
#[Api(since: '1.0.0')]
final class MiddlewareAliasConfig
{
    /**
     * Get the default middleware aliases mapping short names to class-strings.
     *
     * @return array<string, class-string<MiddlewareInterface>>
     */
    #[NoDiscard]
    public static function defaultAliases(): array
    {
        return [
            'cors' => CorsMiddleware::class,
            'csrf' => CsrfMiddleware::class,
            'rate-limit' => RateLimitMiddleware::class,
            // F12.18: a stricter, separate-budget rate limiter for
            // authentication entry points (login, password-reset,
            // 2FA-verify). Using `rate-limit` for these endpoints
            // shares the bucket with general API traffic, which a
            // legitimate user can exhaust through normal use; this
            // dedicated alias has its own per-IP budget so brute-force
            // on auth endpoints fails fast without affecting other
            // routes. Wire on the route: `->middleware(['auth-rate-limit'])`.
            'auth-rate-limit' => AuthenticationRateLimitMiddleware::class,
            'no-cache' => NoCacheMiddleware::class,
            'compression' => CompressionMiddleware::class,
            'tracing' => TracingMiddleware::class,
            'metrics' => MetricsMiddleware::class,
            'body-limit' => BodySizeLimitMiddleware::class,
            'normalize' => RequestNormalizationMiddleware::class,
        ];
    }

    /**
     * Get the default middleware groups.
     *
     * Groups reference aliases by name. These are resolved through
     * the MiddlewareRegistry at runtime.
     *
     * @return array<string, list<class-string<MiddlewareInterface>>>
     */
    #[NoDiscard]
    public static function defaultGroups(): array
    {
        return [
            'web' => [
                RequestNormalizationMiddleware::class,
                CsrfMiddleware::class,
                CompressionMiddleware::class,
            ],
            'api' => [
                RequestNormalizationMiddleware::class,
                RateLimitMiddleware::class,
                CorsMiddleware::class,
            ],
        ];
    }

    /**
     * Apply all default aliases and groups to a registry.
     */
    public static function applyDefaults(MiddlewareRegistry $registry): void
    {
        foreach (self::defaultAliases() as $name => $class) {
            $registry->alias($name, $class);
        }

        foreach (self::defaultGroups() as $name => $middleware) {
            $registry->group($name, $middleware);
        }
    }
}
