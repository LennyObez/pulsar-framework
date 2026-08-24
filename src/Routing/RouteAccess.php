<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;

use function is_string;

/**
 * Who may reach a route, stated at the registration site.
 *
 * A route that declares nothing has not been decided; it has been forgotten.
 * {@see \Pulsar\Auth\Middleware\AuthorizationMiddleware} default-denies a route
 * whose `permissions` attribute is empty, which means an undeclared route's
 * exposure depends on whether that middleware happens to be in the pipeline for
 * a given deployment — reachable by anyone where it is absent, reachable by
 * nobody where it is present. Neither answer was chosen by anyone.
 *
 * This enum is the choice, recorded as a route attribute
 * ({@see RouteAccess::ATTRIBUTE}) alongside the reason
 * ({@see RouteAccess::REASON_ATTRIBUTE}) so that "public" is a statement rather
 * than an omission, and so that a boot-time reporter
 * ({@see RouteAccessReporter}) can tell a decision from a silence.
 *
 * The cases name what enforces the access, not merely who has it — a
 * declaration that names no enforcement is the same omission wearing a label:
 *
 * - {@see RouteAccess::Public}: deliberately reachable by anyone, including an
 *   anonymous request from the public internet. The handler must be safe under
 *   that assumption.
 * - {@see RouteAccess::Operator}: reachable only with the deploy-time operator
 *   credential, checked inside the handler by
 *   {@see \Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard}. Used where a
 *   Bearer token, not a user session, is the credential a scraper or an
 *   on-call engineer actually carries.
 * - {@see RouteAccess::Signed}: reachable only by a caller that proves
 *   possession of a shared secret over the request body — a provider webhook
 *   signature. There is no identity to authorize; the signature is the control.
 * - {@see RouteAccess::Authenticated}: requires a resolved identity and the
 *   permissions named in the route's `permissions` attribute, enforced by the
 *   `auth` middleware alias.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum RouteAccess: string
{
    /** Route attribute key carrying the access decision. */
    public const string ATTRIBUTE = 'access';

    /** Route attribute key carrying the one-line justification for it. */
    public const string REASON_ATTRIBUTE = 'access_reason';

    case Public = 'public';
    case Operator = 'operator';
    case Signed = 'signed';
    case Authenticated = 'authenticated';

    /**
     * Read the decision off a route, or null when the route declares none.
     */
    public static function of(Route $route): ?self
    {
        /** @var mixed $declared */
        $declared = $route->attributes[self::ATTRIBUTE] ?? null;

        return $declared instanceof self ? $declared : null;
    }

    /**
     * Read the recorded justification off a route, or null when it has none.
     */
    public static function reasonOf(Route $route): ?string
    {
        /** @var mixed $reason */
        $reason = $route->attributes[self::REASON_ATTRIBUTE] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Whether reaching this route requires the caller to present something.
     *
     * True for every case but {@see RouteAccess::Public}: the credential differs
     * (session identity, Bearer token, request signature) but its absence is a
     * refusal in all three.
     */
    public function requiresCredential(): bool
    {
        return $this !== self::Public;
    }
}
