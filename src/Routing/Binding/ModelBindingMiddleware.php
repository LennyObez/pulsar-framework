<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Closure;
use JsonException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Attribute\PublicRoute;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\TenantContext;
use Random\RandomException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SodiumException;

use function class_exists;
use function is_array;
use function is_string;
use function str_contains;

/**
 * PSR-15 middleware that resolves route parameters into domain models.
 *
 * Runs after authentication and routing, before the controller.
 * Resolves bound models, enforces authorization based on the
 * configured preset, and attaches resolved models to the request.
 *
 * The per-handler `#[PublicRoute]` attribute lookup is cached in a
 * static map: route handlers are immutable once registered, so the
 * cache is safe for the entire process lifetime and eliminates the
 * per-request `new ReflectionMethod()` + `new ReflectionClass()`
 * cost (M-2 audit response).
 */
#[Internal(reason: 'Middleware wiring; registered in the middleware pipeline by the composition root')]
final class ModelBindingMiddleware implements MiddlewareInterface
{
    /**
     * Per-handler `#[PublicRoute]` lookup cache keyed by `"Class::method"`.
     *
     * @var array<string, bool>
     */
    private static array $publicRouteCache = [];

    /**
     * @param (Closure(ServerRequestInterface): ?IdentityInterface)|null $identityResolver
     *        Resolves the authenticated identity from the request. Provided by
     *        the composition root (typically wraps SecurityContext). Null means
     *        no identity resolution is available.
     */
    public function __construct(
        private readonly ModelBinder $binder,
        private readonly ModelBindingConfig $config,
        private readonly AuthorizationHookInterface $authHook,
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?Closure $identityResolver = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var MatchedRoute|null $matchedRoute */
        $matchedRoute = $request->getAttribute('_route');

        if ($matchedRoute === null || $matchedRoute->parameters === []) {
            return $handler->handle($request);
        }

        $identity = $this->resolveIdentity($request);
        $context = $this->buildResolutionContext($identity);

        try {
            $resolved = $this->binder->bindWithMeta($matchedRoute, $request, $context);
        } catch (ModelBindingException $e) {
            return $this->handleBindingException($e, $request);
        }

        $models = $resolved->models;

        if ($models === []) {
            return $handler->handle($request);
        }

        // Authorization enforcement
        $authResult = $this->enforceAuthorization($request, $matchedRoute, $models, $resolved->metas, $identity);
        if ($authResult !== null) {
            return $authResult;
        }

        // Attach resolved models to the request
        foreach ($models as $paramName => $model) {
            $request = $request->withAttribute('_model_' . $paramName, $model);
        }
        $request = $request->withAttribute('_bound_models', $models);

        return $handler->handle($request);
    }

    /**
     * Resolve the authenticated identity from the request via the injected resolver.
     */
    private function resolveIdentity(ServerRequestInterface $request): ?IdentityInterface
    {
        if ($this->identityResolver === null) {
            return null;
        }

        $identity = ($this->identityResolver)($request);

        if ($identity === null || !$identity->isAuthenticated()) {
            return null;
        }

        return $identity;
    }

    /**
     * Build a ResolutionContext from the current request state.
     */
    private function buildResolutionContext(?IdentityInterface $identity): ResolutionContext
    {
        $tenantId = null;
        if ($this->tenantContext?->isResolved() === true) {
            $tenantId = $this->tenantContext->get()->id;
        }

        $subjectId = $identity?->id();

        return new ResolutionContext(
            tenantId: $tenantId,
            subjectId: $subjectId,
        );
    }

    /**
     * Enforce authorization on resolved models according to the configured preset.
     *
     * @param array<string, object>      $models
     * @param array<string, BindingMeta> $metas  Binding metadata keyed by parameter name
     */
    private function enforceAuthorization(
        ServerRequestInterface $request,
        MatchedRoute $matchedRoute,
        array $models,
        array $metas,
        ?IdentityInterface $identity,
    ): ?ResponseInterface {
        $withoutAuthz = ($matchedRoute->getAttributes()['_without_authorization'] ?? false) === true;
        $isPublicRoute = $this->isPublicRoute($matchedRoute);
        $isRegulated = $this->config->isRegulatedPreset();

        foreach ($models as $paramName => $model) {
            $modelClass = $model::class;

            // Regulated preset: authorization bypass forbidden unless #[PublicRoute]
            if ($isRegulated && $withoutAuthz && !$isPublicRoute) {
                return $this->forbiddenResponse($request);
            }

            // Skip authorization if explicitly opted out on a public route
            if ($withoutAuthz && $isPublicRoute) {
                continue;
            }

            // Skip authorization if opted out on a permissive preset
            if ($withoutAuthz && !$isRegulated) {
                continue;
            }

            // Check if identity is available for authorization
            if ($identity === null) {
                if ($isRegulated) {
                    return $this->unauthorizedResponse($request);
                }
                // Permissive preset: log warning and continue
                $this->logger?->debug('Model binding authorization skipped: no authenticated identity', [
                    'model' => $modelClass,
                    'parameter' => $paramName,
                ]);
                continue;
            }

            // Use the metadata that actually produced this binding so the
            // hook enforces the declared authorization policy. Falling back to
            // a bare meta would silently downgrade every binding to the hook's
            // default permission ('view'), under-authorizing edit/delete routes.
            $meta = $metas[$paramName] ?? new BindingMeta(class: $modelClass);

            if (!$this->authHook->authorize($identity, $model, $meta)) {
                $this->auditAuthzDenied($request, $identity->id(), $modelClass);

                return $this->forbiddenResponse($request);
            }
        }

        return null;
    }

    /**
     * Check if the route handler has the #[PublicRoute] attribute.
     */
    private function isPublicRoute(MatchedRoute $matchedRoute): bool
    {
        /** @var mixed $handler */
        $handler = $matchedRoute->getHandler();
        $handlerInfo = $this->resolveHandlerInfo($handler);

        if ($handlerInfo === null) {
            return false;
        }

        [$class, $method] = $handlerInfo;
        $cacheKey = $class . '::' . $method;

        if (isset(self::$publicRouteCache[$cacheKey])) {
            return self::$publicRouteCache[$cacheKey];
        }

        try {
            // Check method-level attribute first
            $reflectionMethod = new ReflectionMethod($class, $method);
            if ($reflectionMethod->getAttributes(PublicRoute::class) !== []) {
                return self::$publicRouteCache[$cacheKey] = true;
            }

            // Check class-level attribute
            $reflectionClass = new ReflectionClass($class);

            return self::$publicRouteCache[$cacheKey] = $reflectionClass->getAttributes(PublicRoute::class) !== [];
        } catch (ReflectionException) {
            return self::$publicRouteCache[$cacheKey] = false;
        }
    }

    /**
     * Extract controller class and method from a route handler.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveHandlerInfo(mixed $handler): ?array
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            /** @var class-string $class */
            $class = $handler[0];
            return [$class, $handler[1]];
        }

        if (is_string($handler) && class_exists($handler)) {
            /** @var class-string $handler */
            return [$handler, '__invoke'];
        }

        return null;
    }

    /**
     * Handle a ModelBindingException and return the appropriate HTTP response.
     */
    private function handleBindingException(ModelBindingException $e, ServerRequestInterface $request): ResponseInterface
    {
        $code = $e->getCode();

        return match (true) {
            $code === 404 => $this->errorResponse($request, ResponseStatus::NotFound, $e->getMessage()),
            $code === 403 => $this->errorResponse($request, ResponseStatus::Forbidden, 'Forbidden'),
            $code === 400 => $this->errorResponse($request, ResponseStatus::BadRequest, $e->getMessage()),
            default => $this->errorResponse($request, ResponseStatus::InternalServerError, 'Internal Server Error'),
        };
    }

    private function errorResponse(ServerRequestInterface $request, ResponseStatus $status, string $message): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                ['error' => $message, 'status' => $status->value],
                $status->value,
            );
        }

        return new Response(
            statusCode: $status->value,
            body: $message,
        );
    }

    private function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->errorResponse($request, ResponseStatus::Unauthorized, 'Unauthorized');
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->errorResponse($request, ResponseStatus::Forbidden, 'Forbidden');
    }

    private function auditAuthzDenied(ServerRequestInterface $request, string $actor, string $modelClass): void
    {
        try {
            $this->auditLogger?->log(
                event: AuditEvent::Authorization,
                outcome: AuditOutcome::Denied,
                actor: $actor,
                action: 'model_binding_authorization',
                resource: $request->getUri()->getPath(),
                metadata: ['model' => $modelClass],
            );
        } catch (RandomException | JsonException | SodiumException) {
            // Audit logging failure must not disrupt the request flow
        }
    }
}
