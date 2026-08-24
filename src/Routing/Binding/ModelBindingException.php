<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function implode;
use function sprintf;

/**
 * Exception thrown when route model binding fails.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class ModelBindingException extends RuntimeException
{
    /**
     * The bound model could not be found for the given key.
     */
    #[NoDiscard]
    public static function modelNotFound(string $modelClass, string $keyName, string|int $keyValue): self
    {
        return new self(
            sprintf('No [%s] found for [%s] = "%s"', $modelClass, $keyName, $keyValue),
            404,
        );
    }

    /**
     * The authenticated identity is not authorized to access the resolved model.
     *
     * ## Why this carries 404 and not 403
     *
     * It used to be a `403`, beside a `404` for a row that does not exist, and
     * the pair is an existence oracle for anyone the policy refuses. A caller
     * with an account and no entitlement could walk the id space of a bound
     * route and read off which ids are real: `403` means the record exists and
     * you may not see it, `404` means it does not. On a nested route the same
     * pair discloses the PARENT — `/records/{record}/entries/{entry}` answered
     * `403` when the record existed and `404` when it did not, for a caller with
     * no claim on either.
     *
     * The acceptance criterion this framework holds is that neither an anonymous
     * nor an unentitled caller can tell an existing record from an absent one.
     * The anonymous half was closed by deciding the refusal before any row is
     * read ({@see ModelBindingMiddleware::decideAuthorization()}). The other half
     * cannot be closed by ordering — a policy answers "may this caller have THIS
     * record", which needs the record — so it is closed by the answer: a refused
     * caller is told exactly what a caller asking for a row that is not there is
     * told.
     *
     * What is NOT lost is the diagnosis. The message below names the model and
     * the key and goes to the log, which is where
     * {@see ModelBindingMiddleware::handleBindingException()} sends it, and the
     * denial is recorded in the audit chain under `policy_denied`. An operator
     * asking "why did this caller get a 404" has both; the caller has neither.
     *
     * The residue is timing: a refusal reads the row and a miss does not find
     * one, and those are not the same amount of work. That is a property of the
     * store, not of this layer, and this exception does not pretend to close it.
     */
    #[NoDiscard]
    public static function authorizationFailed(string $modelClass, string|int $keyValue): self
    {
        return new self(
            sprintf('Authorization denied for [%s] with key "%s"', $modelClass, $keyValue),
            404,
        );
    }

    /**
     * A {@see BindingAuthorization} exemption was claimed for a route that does
     * not declare the `_without_authorization` opt-out.
     *
     * A wiring error rather than anything a request can cause: the attribute is
     * written into the route table where the application registers its routes.
     * Reaching this means something tried to bind a route under an exemption the
     * route never declared, which is the shape an authorization bypass takes.
     */
    #[NoDiscard]
    public static function undeclaredAuthorizationOptOut(string $route): self
    {
        return new self(
            sprintf(
                'Route [%s] was bound under an authorization opt-out, but it does not declare the '
                . '`_without_authorization` route attribute. Declare the opt-out on the route, or bind '
                . 'the route under a policy gate.',
                $route,
            ),
            500,
        );
    }

    /**
     * An exemption from authorization was claimed under a preset that mandates it.
     */
    #[NoDiscard]
    public static function authorizationMandatory(string $preset, string $route): self
    {
        return new self(
            sprintf(
                'Preset [%s] mandates authorization on every bound model, so route [%s] cannot be bound '
                . 'without one. Configure an authorization hook, or select the standard preset if this '
                . 'application does not authorize bound models.',
                $preset,
                $route,
            ),
            500,
        );
    }

    /**
     * A binding was attempted under an authorization decided for another route.
     *
     * The decision names the route it was made about, so an exemption obtained
     * legally for one route — a public one, say — cannot be carried to another.
     * Reaching this means the two disagreed, and a binding whose authorization
     * was decided about a different route has not been authorized at all.
     */
    #[NoDiscard]
    public static function authorizationForAnotherRoute(string $boundRoute): self
    {
        return new self(
            sprintf(
                'Route [%s] was bound under an authorization decision made for a different route. The '
                . 'decision and the match handed to the binder must be the same route.',
                $boundRoute,
            ),
            500,
        );
    }

    /**
     * A regulated preset requires an authorization policy but none was configured.
     */
    #[NoDiscard]
    public static function missingPolicy(string $modelClass): self
    {
        return new self(
            sprintf(
                'Regulated preset requires an authorization policy for [%s], but none is configured. '
                . 'Register an AuthorizationHookInterface implementation or set an authzPolicy on the binding.',
                $modelClass,
            ),
            500,
        );
    }

    /**
     * No ModelResolverPort implementation is available for the requested model.
     */
    #[NoDiscard]
    public static function missingResolver(string $modelClass): self
    {
        return new self(
            sprintf('No model resolver registered for [%s]', $modelClass),
            500,
        );
    }

    /**
     * The route parameter value does not match the declared key type.
     */
    #[NoDiscard]
    public static function invalidKeyType(string $parameter, string $expectedType, string $actualValue): self
    {
        return new self(
            sprintf(
                'Route parameter [%s] expects type [%s] but received "%s"',
                $parameter,
                $expectedType,
                $actualValue,
            ),
            404,
        );
    }

    /**
     * The key name is not in the allow-list defined by ModelBindingConfig.
     */
    #[NoDiscard]
    public static function invalidKeyName(string $keyName, string $modelClass): self
    {
        return new self(
            sprintf(
                'Key name [%s] is not allowed for model [%s]. Check the allowed_key_names configuration.',
                $keyName,
                $modelClass,
            ),
            400,
        );
    }

    /**
     * A binding declares a scope its other fields contradict.
     */
    #[NoDiscard]
    public static function inconsistentScope(string $modelClass, string $detail): self
    {
        return new self(
            sprintf('Binding metadata for [%s] is inconsistent: %s.', $modelClass, $detail),
            500,
        );
    }

    /**
     * A nested parameter's parent is not bound to any model, so the containment
     * the route path asserts cannot be checked.
     */
    #[NoDiscard]
    public static function undeclaredParent(string $parameter, string $parentParameter, string $routePath): self
    {
        return new self(
            sprintf(
                'Route [%s] contains {%s} within {%s}, but no model is bound to {%s}, so the containment '
                . 'cannot be checked and {%s} is not resolved. Bind the parent — a controller type hint or '
                . 'Router::model(\'%s\', …) — or declare {%s} as BindingScope::Root to resolve it globally on purpose.',
                $routePath,
                $parameter,
                $parentParameter,
                $parentParameter,
                $parameter,
                $parentParameter,
                $parameter,
            ),
            500,
        );
    }

    /**
     * A nested parameter has a parent but the path names no relation to reach
     * it through, so the containment cannot be checked.
     */
    #[NoDiscard]
    public static function undeterminedRelation(string $parameter, string $parentParameter, string $routePath): self
    {
        return new self(
            sprintf(
                'Route [%s] places {%s} inside {%s}, but the segment in front of {%s} names no relation to '
                . 'resolve it through, so {%s} is not resolved. Give it a literal segment of its own — '
                . '/{%s}/<relation>/{%s} — or declare the relation with BindingScope::Contained, or declare '
                . '{%s} as BindingScope::Root to resolve it globally on purpose.',
                $routePath,
                $parameter,
                $parentParameter,
                $parameter,
                $parameter,
                $parentParameter,
                $parameter,
                $parameter,
            ),
            500,
        );
    }

    /**
     * A handler parameter's type hint names more than one class the binder
     * could resolve, so which model the route binds is not decided anywhere.
     *
     * `show(Post|Comment $post)` gives the binder no way to choose which class
     * to look the key up as, and picking the first would make the answer depend
     * on the order the union was written in. Refusing keeps the choice where it
     * can be reviewed.
     *
     * @param list<string> $classes
     */
    #[NoDiscard]
    public static function ambiguousBoundType(string $parameter, string $handler, array $classes): self
    {
        return new self(
            sprintf(
                'Route parameter [%s] on [%s] is type-hinted with %s, and the binder cannot tell which of '
                . 'them the route resolves and authorizes. Name it — Router::model(\'%s\', <Model>::class) '
                . '— or narrow the type hint to one class.',
                $parameter,
                $handler,
                implode(' and ', $classes),
                $parameter,
            ),
            500,
        );
    }

    /**
     * A handler parameter admits an object without naming one, so the route
     * declares a model the binder cannot produce.
     *
     * `object`, `mixed`, `iterable` and `callable` all accept an object and name
     * no class, and `ReflectionNamedType::isBuiltin()` reports every one of them
     * as a builtin — so `show(object $account)` used to be treated exactly like
     * `show(string $account)`: no binding was declared, the plan came back
     * empty, and the middleware handed the request on. On a regulated preset
     * that skipped the identity requirement and the authorization hook with it,
     * and the difference was visible from outside: an anonymous caller got `200`
     * from `show(object $account)` and `401` from `show(Account $account)` on
     * the same route. A type hint must not be able to decide whether
     * authorization runs.
     *
     * Refused as a property of the ROUTE, like every other 500 here: it is read
     * off the handler signature and the route path, memoised per route shape,
     * and therefore the same answer for every caller and every id. A parameter
     * that cannot hold an object at all — `string`, `int`, `array` — is
     * untouched, keeps binding nothing, and reaches the handler with its raw
     * segment as it always has.
     *
     * @param string $declaredType The declaration as written, e.g. `object|string`
     */
    #[NoDiscard]
    public static function unbindableBoundType(string $parameter, string $handler, string $declaredType): self
    {
        return new self(
            sprintf(
                'Route parameter [%s] on [%s] is type-hinted [%s], which accepts an object but names no '
                . 'class the binder can resolve, so the route would bind nothing while declaring that a '
                . 'model belongs in that slot — and a route that binds nothing is not authorized either. '
                . 'Name the model — Router::model(\'%s\', <Model>::class) — or narrow the type hint to '
                . 'the class the route resolves. Use a scalar hint such as [string] if the handler wants '
                . 'the raw segment.',
                $parameter,
                $handler,
                $declaredType,
                $parameter,
            ),
            500,
        );
    }

    /**
     * A segment in front of a nested parameter holds a placeholder without
     * being one, so nothing can be said about what contains the parameter.
     *
     * `u{user}`, `@{user}`, `{user}-x` and `{a}{b}` are all this shape. Whether
     * such a segment addresses a resource is not a property of the URL:
     * `/u{user}/posts/{post}` means the user's posts and `/v{version}/posts/{post}`
     * means every post, and the two are the same string to a parser. Both used
     * to resolve the child globally, which is the containment bypass wearing a
     * path the parser could not read.
     */
    #[NoDiscard]
    public static function unreadableParent(string $parameter, string $segment, string $routePath): self
    {
        return new self(
            sprintf(
                'Route [%s] puts {%s} behind the segment [%s], which holds a placeholder without being one, '
                . 'so whether it addresses a resource that contains {%s} is not something the path says. '
                . '{%s} is not resolved. Give the parent a segment of its own — /{parent}/<relation>/{%s} — '
                . 'or declare {%s} as BindingScope::Root to resolve it globally on purpose.',
                $routePath,
                $parameter,
                $segment,
                $parameter,
                $parameter,
                $parameter,
                $parameter,
            ),
            500,
        );
    }

    /**
     * A placeholder shares its segment with a placeholder in front of it, so
     * the only thing containing it is part of a segment.
     *
     * `{user}-{post}` and `{tenant}.{resource}` read as containment to a person
     * and are the same shape as `{year}-{month}` to a parser, which reads
     * neither. Every placeholder in a segment used to be handed the state from
     * in front of the whole segment, which at the top of a path is "nothing
     * contains me" — so `/{user}-{post}` bound both as roots and handed back
     * any post in the table under a URL naming any user, with no refusal.
     *
     * Separate from {@see unreadableParent()} because the fix is: that one is
     * answered by giving the PARENT a segment, this one by giving each resource
     * a segment of its own.
     */
    #[NoDiscard]
    public static function placeholderSharesSegment(string $parameter, string $segment, string $routePath): self
    {
        return new self(
            sprintf(
                'Route [%s] puts {%s} in the segment [%s], behind another placeholder in that same segment, '
                . 'so the only thing in front of {%s} is part of a segment rather than a resource the path '
                . 'names — `{a}-{b}` is the same shape as `{year}-{month}`, and neither is readable as '
                . 'containment. {%s} is not resolved. Give each resource a segment of its own — '
                . '/{parent}/<relation>/{%s} — or declare {%s} as BindingScope::Root to resolve it globally '
                . 'on purpose. BindingScope::Contained cannot answer this one: it resolves through a parent '
                . 'parameter, and part of a segment is not one.',
                $routePath,
                $parameter,
                $segment,
                $parameter,
                $parameter,
                $parameter,
                $parameter,
            ),
            500,
        );
    }

    /**
     * A binding is declared contained, but the route path puts nothing in
     * front of it to be contained by.
     */
    #[NoDiscard]
    public static function containedWithoutParent(string $parameter, string $routePath): self
    {
        return new self(
            sprintf(
                'Binding for {%s} is declared contained, but route [%s] has no resource placeholder in front '
                . 'of it to be contained by.',
                $parameter,
                $routePath,
            ),
            500,
        );
    }

    /**
     * A scoped binding's parent was never resolved, so the child must not be.
     */
    #[NoDiscard]
    public static function unresolvedParent(string $parameter, string $parentParameter): self
    {
        return new self(
            sprintf(
                'Route parameter [%s] resolves through [%s], which did not resolve.',
                $parameter,
                $parentParameter,
            ),
            404,
        );
    }

    /**
     * Attempt to bypass authorization on a regulated route without the #[PublicRoute] attribute.
     */
    #[NoDiscard]
    public static function authBypassForbidden(string $routeName): self
    {
        return new self(
            sprintf(
                'Authorization bypass is forbidden on regulated route [%s]. '
                . 'Add the #[PublicRoute] attribute to explicitly opt out of authorization.',
                $routeName,
            ),
            403,
        );
    }
}
