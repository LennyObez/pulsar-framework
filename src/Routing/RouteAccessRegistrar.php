<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;

use function implode;
use function sprintf;

/**
 * Registers a route together with the decision about who may reach it.
 *
 * The router's verb sugar (`$router->get(...)`) attaches no middleware and no
 * attributes, so every route registered through it declares nothing. That is
 * not a neutral default: {@see \Pulsar\Auth\Middleware\AuthorizationMiddleware}
 * default-denies a route with no `permissions`, so the same registration is
 * open where that middleware is absent from the pipeline and closed where it is
 * present. The exposure of a framework route must not depend on a deployment's
 * middleware ordering.
 *
 * Each method here is one line at the call site and forces the decision to be
 * spelled: which {@see RouteAccess} case, and why. The `why` is stored on the
 * route, not in a comment, so {@see RouteAccessReporter} can read it back at
 * boot and an auditor can read it out of the route table.
 *
 * {@see RouteAccessRegistrar::authenticated()} additionally fails closed. A
 * route that names permissions but whose `auth` alias is unregistered (the app
 * disabled authentication in config/security.php) would dispatch straight to
 * its handler with the permission list ignored. Rather than register a guard
 * that cannot run, the route is not registered at all and the omission is
 * logged: 404 is the honest answer for a feature whose authorization is absent.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RouteAccessRegistrar
{
    /** Alias under which AuthWiring publishes AuthorizationMiddleware. */
    public const string AUTH_ALIAS = 'auth';

    /**
     * Permission sentinel meaning "any authenticated identity"; recognised by
     * {@see \Pulsar\Auth\Middleware\AuthorizationMiddleware}.
     */
    public const string ANY_AUTHENTICATED = '_authenticated';

    public function __construct(
        private RouterInterface $router,
        private MiddlewareRegistry $middlewareRegistry,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * A route anyone may reach, on purpose.
     *
     * @param list<Method>          $methods
     * @param list<string>          $middleware  Non-authorization middleware (e.g. throttling)
     * @param array<string, string> $constraints
     */
    public function publicRoute(
        array $methods,
        string $path,
        mixed $handler,
        ?string $name,
        string $because,
        array $middleware = [],
        array $constraints = [],
    ): void {
        $this->register(RouteAccess::Public, $methods, $path, $handler, $name, $because, [], $middleware, $constraints);
    }

    /**
     * A route only the deploy-time operator credential opens.
     *
     * No `auth` middleware: the credential is a Bearer token checked inside the
     * handler ({@see \Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard}),
     * because the callers are scrapers and on-call engineers, who carry a token
     * and not a session. `$because` must name what performs that check.
     *
     * @param list<Method>          $methods
     * @param list<string>          $middleware
     * @param array<string, string> $constraints
     */
    public function operatorRoute(
        array $methods,
        string $path,
        mixed $handler,
        ?string $name,
        string $because,
        array $middleware = [],
        array $constraints = [],
    ): void {
        $this->register(RouteAccess::Operator, $methods, $path, $handler, $name, $because, [], $middleware, $constraints);
    }

    /**
     * A route whose caller proves a shared secret over the request body.
     *
     * There is no identity to authorize — the signature is the control — so
     * `$because` must name the verifier that checks it.
     *
     * @param list<Method>          $methods
     * @param list<string>          $middleware
     * @param array<string, string> $constraints
     */
    public function signedRoute(
        array $methods,
        string $path,
        mixed $handler,
        ?string $name,
        string $because,
        array $middleware = [],
        array $constraints = [],
    ): void {
        $this->register(RouteAccess::Signed, $methods, $path, $handler, $name, $because, [], $middleware, $constraints);
    }

    /**
     * A route requiring a resolved identity holding every named permission.
     *
     * Registers nothing when the `auth` alias is unavailable — see the class
     * docblock. Use {@see RouteAccessRegistrar::ANY_AUTHENTICATED} when the
     * grant genuinely is "any authenticated identity"; an empty list is
     * rejected, because AuthorizationMiddleware reads it as deny-everyone and a
     * route closed to every caller is a broken feature, not a guarded one.
     *
     * The permission list is typed `list<string>` rather than
     * `non-empty-list<string>` on purpose. Most call sites build it from config
     * or from a variable, where no analyser can prove it non-empty, so the
     * guarantee has to be a check that runs — and a check nothing can reach is
     * the defect this class exists to close.
     *
     * @param list<Method>          $methods
     * @param list<string>          $permissions
     * @param list<string>          $middleware  Extra middleware, appended after `auth`
     * @param array<string, string> $constraints
     *
     * @throws RoutingException When $permissions is empty.
     */
    public function authenticated(
        array $methods,
        string $path,
        mixed $handler,
        ?string $name,
        array $permissions,
        string $because,
        array $middleware = [],
        array $constraints = [],
    ): void {
        if ($permissions === []) {
            throw RoutingException::accessDeclarationWithoutPermission($path);
        }

        if (!$this->middlewareRegistry->hasAlias(self::AUTH_ALIAS)) {
            $this->logger?->error(sprintf(
                'Route %s requires permissions [%s] but the "%s" middleware alias is unregistered '
                . '(authentication is disabled in config/security.php), so nothing would enforce them. '
                . 'The route is not registered.',
                $path,
                implode(', ', $permissions),
                self::AUTH_ALIAS,
            ));

            return;
        }

        $this->register(
            RouteAccess::Authenticated,
            $methods,
            $path,
            $handler,
            $name,
            $because,
            $permissions,
            [self::AUTH_ALIAS, ...$middleware],
            $constraints,
        );
    }

    /**
     * @param list<Method>          $methods
     * @param list<string>          $permissions
     * @param list<string>          $middleware
     * @param array<string, string> $constraints
     */
    private function register(
        RouteAccess $access,
        array $methods,
        string $path,
        mixed $handler,
        ?string $name,
        string $because,
        array $permissions,
        array $middleware,
        array $constraints,
    ): void {
        $attributes = [
            RouteAccess::ATTRIBUTE => $access,
            RouteAccess::REASON_ATTRIBUTE => $because,
        ];

        if ($permissions !== []) {
            $attributes['permissions'] = $permissions;
        }

        $this->router->add(new Route(
            methods: $methods,
            path: $path,
            handler: $handler,
            name: $name,
            attributes: $attributes,
            middleware: $middleware,
            constraints: $constraints,
        ));
    }
}
