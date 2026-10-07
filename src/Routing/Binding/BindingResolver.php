<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Core\Controller\HandlerParameter;
use Pulsar\Routing\MatchedRoute;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function array_key_exists;
use function array_keys;
use function array_map;
use function class_exists;
use function count;
use function explode;
use function implode;
use function preg_match_all;
use function trim;

use const PREG_SET_ORDER;

/**
 * Resolves binding metadata for route parameters.
 *
 * Two questions are answered here, from two different sources, and keeping
 * them apart is the point of this class.
 *
 * **What each parameter is** comes from a pre-compiled binding map
 * (production), from reflection on the controller's type hints (development),
 * or from {@see ExplicitBinding} registered with `Router::model()`. Any of the
 * three may name the model class for a parameter.
 *
 * **How each parameter is constrained** comes from the route path and nothing
 * else. See {@see decideScopes()} for the rule. The handler's signature is not
 * consulted for this: a signature is a statement about what the handler finds
 * convenient, and a URL is a statement about which resource contains which.
 * Letting the first decide the second is how `/users/1/posts/20` came to return
 * a post belonging to user 2.
 *
 * ## Both answers are decided once, not once per request
 *
 * Every input to both questions is fixed when the route table is built. The
 * class is therefore memoised the way {@see \Pulsar\Core\Kernel} memoises its
 * handler descriptors — an instance-level map keyed by a stable identity
 * string, never invalidated, holding plain data — rather than through a second,
 * differently-shaped cache. {@see shapeKey()} states what "the same route" means
 * and why each part of it is load-bearing.
 *
 * ## A refusal is announced once, here, because it is decided once, here
 *
 * A refusal out of this class is a route that cannot be served for anybody: it
 * is read off declarations, it is the same answer for every caller and every
 * id, and {@see resolveForRoute()} computes it exactly once and replays it
 * afterwards. So the operator's diagnosis is written the one time the decision
 * is made, at `error`, from here.
 *
 * It used to be written by {@see ModelBindingMiddleware} instead, on every
 * request that hit the route. That is the same permanent fact reprinted per
 * request — measured at 1,761 bytes and 853 µs each, with no ceiling, on a
 * route an unauthenticated caller can reach. Moving the line to the decision
 * removes the amplification without introducing anything that could suppress a
 * report: there is no counter and no claim to run out of, only a memo that has
 * to exist for the replay to happen at all.
 */
#[Internal(reason: 'Implementation detail of the model binding pipeline')]
final class BindingResolver
{
    /**
     * One `{name}`, `{name:key}`, `{name?}` or `{name:key?}` placeholder.
     *
     * Group 1 is the parameter name — the name the router captures and keys
     * {@see MatchedRoute::$parameters} by — and group 2 the optional custom
     * key name. Kept in step with the same construct in
     * {@see \Pulsar\Routing\Route::pathToPattern()} and
     * {@see \Pulsar\Routing\RouteCompiler}: a placeholder those two compile
     * but this one cannot read would silently bind by the wrong column.
     */
    private const string PLACEHOLDER_PATTERN = '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z_][a-zA-Z0-9_]*))?\??}#';

    /**
     * The decision for each route shape this process has already seen.
     *
     * A refusal is stored alongside the successes and rethrown, because "this
     * containment cannot be checked" is as permanent a property of the route as
     * any binding in it. Recomputing it would leave the fail-closed path — the
     * one an attacker can aim at by hammering a misdeclared nested route — as
     * the only path still paying for reflection on every request. The rethrown
     * instance carries the trace of the request that first hit it; the message
     * and code, which are what the response and the log are built from, are
     * identical either way.
     *
     * @var array<string, array<string, BindingMeta>|ModelBindingException>
     */
    private array $decided = [];

    /**
     * @param list<ExplicitBinding> $explicitBindings
     * @param LoggerInterface|null  $logger Where a route that cannot be served is announced,
     *        once per shape. Null leaves the refusal silent here; the middleware
     *        still counts every request the route refuses.
     */
    public function __construct(
        private readonly array $explicitBindings = [],
        private readonly ?CompiledBindingMap $compiledMap = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Resolve binding metadata for all parameters of a matched route.
     *
     * The answer is memoised per route shape. Two requests for the same route
     * differ only in the parameter VALUES, which decide nothing here — what a
     * parameter binds to and how it is constrained are read from the handler
     * signature, the route path, the route name and the resolver's own
     * bindings, all of which are frozen once the route table is built.
     *
     * @param class-string $controllerClass
     *
     * @return array<string, BindingMeta> Ordered by path position; every entry carries a decided scope
     *
     * @throws ModelBindingException When the route path asserts a containment that cannot be
     *                               checked, or a handler parameter declares a model the binder
     *                               cannot decide on
     */
    public function resolveForRoute(
        MatchedRoute $matchedRoute,
        string $controllerClass,
        string $controllerMethod,
    ): array {
        $shapeKey = self::shapeKey($matchedRoute, $controllerClass, $controllerMethod);
        $memoised = $this->decided[$shapeKey] ?? null;

        if ($memoised instanceof ModelBindingException) {
            throw $memoised;
        }

        if ($memoised !== null) {
            return $memoised;
        }

        try {
            $decided = $this->decideForRoute($matchedRoute, $controllerClass, $controllerMethod);
        } catch (ModelBindingException $refusal) {
            $this->decided[$shapeKey] = $refusal;

            // Inside the memo write, so it happens on the one pass that
            // computes the refusal and on no replay of it. Every factory that
            // reaches here names declarations only — a parameter, a handler, a
            // route path, a model class — and never a value the caller sent, so
            // the line is safe to write in full and the diagnosis is complete
            // the first time.
            $this->logger?->error(
                'Route model binding cannot serve this route: ' . $refusal->getMessage(),
                [
                    'route_shape' => $shapeKey,
                    'status' => $refusal->getCode(),
                    'exception' => $refusal,
                ],
            );

            throw $refusal;
        }

        return $this->decided[$shapeKey] = $decided;
    }

    /**
     * Identify the route shape a decision belongs to.
     *
     * Four things, and each one has produced a wrong answer when left out:
     *
     *  - **the handler**, because the type hints on `Class::method` are what
     *    name the model behind each parameter;
     *  - **the route path**, because containment and the relation to resolve a
     *    child through are read from it and from nothing else — two routes can
     *    share a handler and a parameter list while nesting the child under
     *    different relations;
     *  - **the route name**, because that is the key the compiled map is
     *    indexed by, so an unnamed mirror of a named route must not be served
     *    the named route's compiled entry;
     *  - **the parameter names the match produced**, because an optional
     *    placeholder makes one route path match with and without a level, and a
     *    binding for a parameter the URL did not supply is a binding for
     *    nothing.
     *
     * Every component is route-table data. Nothing a caller sends — no
     * parameter value, no header, no query string — reaches the key, so the map
     * is bounded by the route table and no volume of traffic can grow it. That
     * is the same property {@see \Pulsar\Core\Kernel} relies on to keep its
     * handler descriptors for the process lifetime without invalidation.
     *
     * @param class-string $controllerClass
     */
    private static function shapeKey(
        MatchedRoute $matchedRoute,
        string $controllerClass,
        string $controllerMethod,
    ): string {
        return $controllerClass . '::' . $controllerMethod
            . "\0" . ($matchedRoute->getName() ?? '')
            . "\0" . $matchedRoute->route->path
            . "\0" . implode(',', array_keys($matchedRoute->parameters));
    }

    /**
     * Decide one route shape from scratch: map or reflection, then overrides,
     * then the path.
     *
     * @param class-string $controllerClass
     *
     * @return array<string, BindingMeta>
     *
     * @throws ModelBindingException When the route path asserts a containment that cannot be
     *                               checked, or a handler parameter declares a model the binder
     *                               cannot decide on
     */
    private function decideForRoute(
        MatchedRoute $matchedRoute,
        string $controllerClass,
        string $controllerMethod,
    ): array {
        $routeName = $matchedRoute->getName();
        $declared = [];

        // Fast path: use the compiled map if available and the route is named.
        if ($this->compiledMap !== null && $routeName !== null) {
            $declared = $this->compiledMap->getForRoute($routeName);
        }

        $ambiguous = [];
        $unbindable = [];

        if ($declared === []) {
            // Slow path: reflection over the controller's type hints.
            [$declared, $ambiguous, $unbindable] = self::reflectDeclarations(
                $controllerClass,
                $controllerMethod,
                $matchedRoute->parameters,
            );
        }

        $declared = $this->applyExplicitOverrides($declared, $matchedRoute);

        $handler = $controllerClass . '::' . $controllerMethod;

        // Both refusals are raised here rather than inside the reflection, and
        // the ordering is the whole point: each one's own advice is "name it
        // with Router::model()", so it has to be raised AFTER the explicit
        // bindings that would take that advice have been applied. Raised
        // earlier they would be unanswerable — an author who did exactly what
        // the message said would get the same message back.
        foreach ($ambiguous as $paramName => $classes) {
            if (!array_key_exists($paramName, $declared)) {
                throw ModelBindingException::ambiguousBoundType($paramName, $handler, $classes);
            }
        }

        foreach ($unbindable as $paramName => $declaredType) {
            if (!array_key_exists($paramName, $declared)) {
                throw ModelBindingException::unbindableBoundType($paramName, $handler, $declaredType);
            }
        }

        return self::decideScopes($declared, $matchedRoute->route->path);
    }

    /**
     * Resolve implicit bindings via controller method reflection.
     *
     * Inspects type hints on the controller method parameters. For each
     * parameter whose name matches a route parameter and whose type hint is a
     * class (not a scalar or built-in), declares a binding — and then hands the
     * declarations to {@see decideScopes()}, which is where the reflected
     * signature stops having any say.
     *
     * Deliberately NOT memoised, and deliberately not on the request path:
     * {@see ModelBinder} calls {@see resolveForRoute()} and only that. This one
     * answers the narrower question "what would reflection alone say", which is
     * a diagnostic, so it reflects every time it is asked. Anything that starts
     * calling it per request belongs on {@see resolveForRoute()} instead.
     *
     * An ambiguous type hint refuses here with no deferral, because there is no
     * later step to defer to: this method answers for reflection alone, and the
     * explicit binding that would settle the ambiguity is by definition not part
     * of the answer.
     *
     * @param class-string $controllerClass
     *
     * @return array<string, BindingMeta>
     *
     * @throws ModelBindingException When the route path asserts a containment that
     *                               cannot be checked, or a type hint names more
     *                               than one bindable class, or it names none
     *                               while still admitting an object
     */
    public function resolveImplicit(
        string $controllerClass,
        string $controllerMethod,
        MatchedRoute $matchedRoute,
    ): array {
        [$declared, $ambiguous, $unbindable] = self::reflectDeclarations(
            $controllerClass,
            $controllerMethod,
            $matchedRoute->parameters,
        );

        $handler = $controllerClass . '::' . $controllerMethod;

        foreach ($ambiguous as $paramName => $classes) {
            throw ModelBindingException::ambiguousBoundType($paramName, $handler, $classes);
        }

        foreach ($unbindable as $paramName => $declaredType) {
            throw ModelBindingException::unbindableBoundType($paramName, $handler, $declaredType);
        }

        return self::decideScopes($declared, $matchedRoute->route->path);
    }

    /**
     * Declare a binding for every controller parameter that names a route
     * parameter and type-hints a class.
     *
     * A union or intersection names a class too, and used to name none. `!$type
     * instanceof ReflectionNamedType` skipped the parameter outright, so
     * `show(Post|string $post)` on `/users/{user}/posts/{post}` declared no
     * binding at all: nothing was resolved, nothing was authorized, no
     * containment was checked, and the handler was handed the raw id in a slot
     * that had declared a Post acceptable. {@see classifyType()} reads them.
     *
     * A hint naming two classes is REPORTED rather than thrown, and the caller
     * decides. `Post|Comment` is unanswerable from reflection alone, but an
     * explicit binding answers it, and explicit bindings are applied after this
     * runs. A hint that names NO class while still admitting an object is
     * reported the same way and for the same reason.
     *
     * @param class-string $controllerClass
     * @param array<string, string> $routeParameters
     *
     * @return array{array<string, BindingMeta>, array<string, list<string>>, array<string, string>}
     *         Declarations; then the parameters whose hint named more than one bindable
     *         class, mapped to those classes; then the parameters whose hint admits an
     *         object without naming one, mapped to the declaration as written
     */
    private static function reflectDeclarations(
        string $controllerClass,
        string $controllerMethod,
        array $routeParameters,
    ): array {
        try {
            $reflection = new ReflectionMethod($controllerClass, $controllerMethod);
        } catch (ReflectionException) {
            return [[], [], []];
        }

        $declared = [];
        $ambiguous = [];
        $unbindable = [];

        foreach ($reflection->getParameters() as $param) {
            $paramName = $param->getName();

            // A `{param:key}` placeholder is still captured under `param`, so a
            // method parameter always matches a route parameter by bare name.
            if (!array_key_exists($paramName, $routeParameters)) {
                continue;
            }

            $type = $param->getType();

            if ($type === null) {
                // No declaration at all. There is nothing that says a model
                // belongs in the slot, so there is nothing to refuse: an
                // untyped parameter is the same pass-through it has always
                // been, and it is what `HandlerParameter::accepts()` answers
                // for on the delivery side too.
                continue;
            }

            [$classes, $admitsObject] = self::classifyType($type);

            if ($classes === []) {
                if ($admitsObject) {
                    $unbindable[$paramName] = self::describeType($type);
                }

                continue;
            }

            if (count($classes) > 1) {
                $ambiguous[$paramName] = $classes;

                continue;
            }

            /** @var class-string $className */
            $className = $classes[0];

            $declared[$paramName] = new BindingMeta(
                class: $className,
                keyName: 'id',
                keyType: 'string',
            );
        }

        return [$declared, $ambiguous, $unbindable];
    }

    /**
     * What a type hint names, and whether an object could satisfy it anyway.
     *
     * ## The classes
     *
     * Interfaces and builtins are not bindable and never have been: a resolver
     * is asked for a concrete class, so `string`, `int` and `Countable` all read
     * as "this parameter is not a model". Filtering on `class_exists()` alone is
     * what makes an intersection like `Post&Countable` unambiguous rather than a
     * refusal, and what makes `Post|string` name exactly one candidate.
     *
     * Returning a list rather than picking one is deliberate: two classes is a
     * question this method cannot answer, and answering it by taking the first
     * would make which model the route resolves depend on the order the union
     * was written in.
     *
     * ## The second answer, and the hole it closes
     *
     * "Names no class" was read as "this parameter is not a model", and for
     * `string $note` on `/notes/{note}` that is right — the handler wants the
     * raw segment and gets it. For `object`, `mixed`, `iterable` and `callable`
     * it is wrong twice over: `ReflectionNamedType::isBuiltin()` answers true
     * for all four, so the parameter was skipped, the route bound nothing,
     * {@see ModelBinder::plan()} returned an empty plan, and the middleware
     * handed the request on — which under a regulated preset skipped the
     * identity requirement AND the authorization hook. A type hint was an off
     * switch for authorization: `show(object $account)` served an anonymous
     * caller a `200` where `show(Account $account)` served a `401`.
     *
     * The two families are told apart by
     * {@see HandlerParameter::builtinCanHoldObject()}, which is where the
     * framework already answers this question on the argument-resolver side —
     * and answering it a second time here, out of `isBuiltin()`, is exactly what
     * produced the disagreement. A parameter that cannot hold an object keeps
     * its documented pass-through; one that can hold an object but names none is
     * a route this resolver cannot serve, and {@see decideForRoute()} refuses it.
     *
     * @return array{list<string>, bool} The bindable classes, then whether any named
     *         type in the declaration admits an object at all
     */
    private static function classifyType(ReflectionType $type): array
    {
        $classes = [];
        $admitsObject = false;

        foreach (self::namedTypes($type) as $named) {
            $typeName = $named->getName();

            if ($named->isBuiltin()) {
                if (HandlerParameter::builtinCanHoldObject($typeName)) {
                    $admitsObject = true;
                }

                continue;
            }

            if (!class_exists($typeName)) {
                // An interface, or a name that does not resolve. Both are the
                // documented pass-through above, and both keep it: narrowing
                // that would change what every interface-typed route parameter
                // in every application already does, which is a decision of its
                // own rather than a consequence of the builtin one.
                continue;
            }

            $classes[$typeName] = true;
        }

        return [array_keys($classes), $admitsObject];
    }

    /**
     * Render a declaration the way it was written, for the refusal message.
     *
     * `(string) $type` says the same thing in one line, and PHP's own stubs
     * deprecate it — so the grammar is walked instead. Three shapes, which is
     * the whole of it: a name, a union of them, an intersection of them.
     */
    private static function describeType(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed'
                ? '?' . $name
                : $name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(self::describeType(...), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(self::describeType(...), $type->getTypes()));
        }

        // ReflectionType is abstract and the three subclasses above are all of
        // them. A fourth would be new type grammar in a future PHP, and naming
        // the reflection class is a true statement about a declaration this
        // version cannot render — the parameter name and the handler in the
        // same message are what an author needs to find it either way.
        return $type::class;
    }

    /**
     * Flatten a reflected type to the named types it is built from.
     *
     * Handles PHP 8.2 DNF types — `(Post&Countable)|string` puts an
     * intersection inside a union — by descending one level, which is as deep as
     * the grammar goes.
     *
     * @return list<ReflectionNamedType>
     */
    private static function namedTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        if (!$type instanceof ReflectionUnionType && !$type instanceof ReflectionIntersectionType) {
            return [];
        }

        $named = [];

        foreach ($type->getTypes() as $member) {
            foreach (self::namedTypes($member) as $inner) {
                $named[] = $inner;
            }
        }

        return $named;
    }

    /**
     * Apply explicit binding overrides to the declared bindings.
     *
     * `Router::model()` states what a parameter is, and — when the caller says
     * so — how it is scoped. Saying nothing about the scope leaves whatever the
     * previous declaration recorded, so an explicit binding registered to swap
     * a resolver cannot quietly drop a compiled map's `Contained` relation.
     *
     * @param array<string, BindingMeta> $declared
     *
     * @return array<string, BindingMeta>
     */
    private function applyExplicitOverrides(array $declared, MatchedRoute $matchedRoute): array
    {
        foreach ($this->explicitBindings as $explicit) {
            $paramName = $explicit->parameter;

            if (!$matchedRoute->hasParameter($paramName)) {
                continue;
            }

            $existing = $declared[$paramName] ?? null;

            $saysNothingAboutScope = $explicit->scope === BindingScope::Path;
            $scope = $saysNothingAboutScope && $existing !== null ? $existing->scope : $explicit->scope;
            $relation = $saysNothingAboutScope && $existing !== null
                ? $existing->parentRelation
                : $explicit->parentRelation;

            $declared[$paramName] = new BindingMeta(
                class: $explicit->modelClass,
                keyName: $existing === null ? 'id' : $existing->keyName,
                keyType: $existing === null ? 'string' : $existing->keyType,
                scope: $scope,
                parentRelation: $scope === BindingScope::Contained ? $relation : null,
                authzPolicy: $existing?->authzPolicy,
                customResolver: $explicit->resolverClass,
            );
        }

        return $declared;
    }

    /**
     * Order the bindings by path position and decide each one's scope.
     *
     * ## Order
     *
     * The returned map is ordered by where each parameter sits in the route
     * path, not by the controller signature. `show(Post $post, User $user)` on
     * `/users/{user}/posts/{post}` therefore resolves the user first, exactly
     * as the path reads.
     *
     * ## The rule
     *
     * A **resource placeholder** is a placeholder that occupies a whole path
     * segment and shares it with nothing: `/{post}`, not `/v{version}`, not
     * `/posts-{post}` and not `/{a}{b}`. That is the entire test, and it is
     * deliberately an over-approximation — see below. A bound resource
     * placeholder is contained by the resource placeholder **immediately** in
     * front of it, and by no other: a parameter is never scoped to a
     * grandparent, whether or not the level in between happens to be bound,
     * because the nearest one is the only candidate this loop ever considers.
     * The relation is the literal segment directly in front of the child, used
     * verbatim: on `/users/{user}/posts/{post}` the post resolves through
     * `User::$posts`.
     *
     * ## What makes a placeholder a resource, and why it is nothing more
     *
     * Occupying a segment is a weak test, and it is the strongest one a URL
     * supports. `/{locale}/posts/{post}`, `/{tenant}/posts/{post}` and
     * `/{user}/posts/{post}` are the same string to a parser, and only the last
     * one means containment. So a locale, a tenant slug and an API version
     * written `{version}` are all treated as resource placeholders here, and the
     * route refuses — `undeclaredParent`, because nothing binds a model to them
     * — rather than guessing. Refusing is the safe half of the ambiguity: the
     * alternative reading unscopes `/{user}/posts/{post}`, which is the bug this
     * class exists to prevent, and it cannot be had for the addressing prefixes
     * without also handing it to the real parent.
     *
     * A placeholder that shares its segment is refused from the other side. It
     * is never a parent, because `u{user}` and `v{version}` are equally
     * unreadable, and it does not let containment pass through it either: a
     * bound placeholder after one is refused with
     * {@see ModelBindingException::unreadableParent()} instead of silently
     * becoming a root. `/u{user}/posts/{post}` used to hand back any post in the
     * table.
     *
     * ## A segment two placeholders share
     *
     * The same rule, applied inside a segment rather than across segments,
     * because a segment is unreadable to the placeholders in it as much as to
     * the ones behind it. Reading left to right:
     *
     *  - the **first** placeholder in a segment is contained by whatever
     *    precedes the segment — a resource placeholder, or nothing at all at the
     *    top of a path, where it is a root. `/{user}-{other}` resolves `{user}`
     *    on its own key, exactly as `/u{user}` does;
     *  - **every placeholder after it** stands behind something the parser
     *    cannot read as a resource, and is refused with
     *    {@see ModelBindingException::placeholderSharesSegment()}.
     *
     * `{user}-{post}`, `{tenant}.{resource}` and `{a}{b}` read as containment to
     * a person and are the same shape as `{year}-{month}` to a parser, which
     * reads neither. So the least legible containment in a URL is the one that
     * fails closed hardest: `BindingScope::Contained` is not even an escape
     * here, because a contained binding resolves through a parent PARAMETER and
     * a fragment of a segment is not one. `BindingScope::Root` is, and it is the
     * whole of what the author has to write down.
     *
     * Both refusals are answered the same way, in one line the author writes
     * down: {@see BindingScope::Root} for a parameter that really is global, or
     * {@see BindingScope::Contained} naming the relation for one that really is
     * nested. Neither can be reached by leaving something out.
     *
     * Containment is asserted by the URL, so it is checked whether or not the
     * handler wants the parent: `show(Post $post)` on that route still resolves
     * the user, purely to scope the post by it.
     *
     * ## Fail closed
     *
     * When the path asserts a containment that cannot be checked — no model is
     * bound to the parent, no segment names the relation, or the segment that
     * would have been the parent cannot be read — the child is not resolved and
     * the route raises. There is no branch on which it resolves globally
     * instead, which is what a `/compare/{left}/{right}`, a
     * `/users/{user}/posts-{post}` and a `/u{user}/posts/{post}` used to fall
     * through to.
     *
     * The two ways out are both written down and both typed:
     * {@see BindingScope::Contained} names the relation for a segment that
     * cannot, and {@see BindingScope::Root} says a nested resource is global on
     * purpose. Neither can be reached by leaving something out.
     *
     * @param array<string, BindingMeta> $declared
     *
     * @return array<string, BindingMeta>
     *
     * @throws ModelBindingException
     */
    private static function decideScopes(array $declared, string $routePath): array
    {
        if ($declared === []) {
            return $declared;
        }

        $ordered = [];

        foreach (self::parsePlaceholders($routePath) as $placeholder) {
            $paramName = $placeholder['name'];
            $meta = $declared[$paramName] ?? null;

            if ($meta === null) {
                continue;
            }

            $ordered[$paramName] = self::decide($meta, $paramName, $placeholder, $declared, $routePath);
        }

        // A bound parameter that is not in the path at all — a host
        // placeholder, say — has nothing in front of it to be contained by, so
        // it is a root. It keeps its place behind the path-ordered ones, where
        // it can never become anyone's parent either.
        foreach ($declared as $paramName => $meta) {
            if (array_key_exists($paramName, $ordered)) {
                continue;
            }

            if ($meta->scope === BindingScope::Contained) {
                throw ModelBindingException::containedWithoutParent($paramName, $routePath);
            }

            $ordered[$paramName] = self::rebuild($meta, $meta->keyName, $meta->keyType, BindingScope::Root, null, null);
        }

        return $ordered;
    }

    /**
     * Decide one parameter's key and scope from its place in the path.
     *
     * @param array{
     *     name: string,
     *     key: string|null,
     *     relation: string|null,
     *     parent: string|null,
     *     unreadableParent: string|null,
     *     sharedSegment: string|null,
     * } $placeholder
     * @param array<string, BindingMeta> $declared
     *
     * @throws ModelBindingException
     */
    private static function decide(
        BindingMeta $meta,
        string $paramName,
        array $placeholder,
        array $declared,
        string $routePath,
    ): BindingMeta {
        $customKey = $placeholder['key'];
        $keyName = $customKey ?? $meta->keyName;
        // A key named in the path is a column, not necessarily numeric:
        // `{user:slug}` must not go through integer coercion.
        $keyType = $customKey === null ? $meta->keyType : 'string';

        if ($meta->scope === BindingScope::Root) {
            return self::rebuild($meta, $keyName, $keyType, BindingScope::Root, null, null);
        }

        $parentParameter = $placeholder['parent'];

        if ($parentParameter === null) {
            // Something IS in front of it and cannot be read. Both refusals come
            // before the two branches below, because neither of those is true:
            // this is not the top of the path, and naming a relation does not
            // name the parameter the parent would be resolved into.
            //
            // The narrower one first. A placeholder standing behind another one
            // inside its own segment is refused for a reason of its own and
            // answered differently — give each resource a segment — and the
            // other message cannot say that, because there the segment at fault
            // is not the one the parameter is in.
            $shared = $placeholder['sharedSegment'];

            if ($shared !== null) {
                throw ModelBindingException::placeholderSharesSegment($paramName, $shared, $routePath);
            }

            $unreadable = $placeholder['unreadableParent'];

            if ($unreadable !== null) {
                throw ModelBindingException::unreadableParent($paramName, $unreadable, $routePath);
            }

            if ($meta->scope === BindingScope::Contained) {
                throw ModelBindingException::containedWithoutParent($paramName, $routePath);
            }

            // Nothing in front of it: this is the top of the path.
            return self::rebuild($meta, $keyName, $keyType, BindingScope::Root, null, null);
        }

        if (!array_key_exists($parentParameter, $declared)) {
            throw ModelBindingException::undeclaredParent($paramName, $parentParameter, $routePath);
        }

        // A declared relation wins over the path's, which is the whole purpose
        // of declaring one; a path segment supplies it otherwise.
        $relation = $meta->scope === BindingScope::Contained ? $meta->parentRelation : $placeholder['relation'];

        if ($relation === null) {
            throw ModelBindingException::undeterminedRelation($paramName, $parentParameter, $routePath);
        }

        return self::rebuild($meta, $keyName, $keyType, BindingScope::Contained, $relation, $parentParameter);
    }

    /**
     * Copy a binding with the decided key and scope.
     *
     * {@see BindingMeta} is rebuilt rather than cloned-with, since a readonly
     * property may only be written from inside the class that declares it.
     */
    private static function rebuild(
        BindingMeta $meta,
        string $keyName,
        string $keyType,
        BindingScope $scope,
        ?string $parentRelation,
        ?string $parentParameter,
    ): BindingMeta {
        return new BindingMeta(
            class: $meta->class,
            keyName: $keyName,
            keyType: $keyType,
            scope: $scope,
            parentRelation: $parentRelation,
            authzPolicy: $meta->authzPolicy,
            customResolver: $meta->customResolver,
            parentParameter: $parentParameter,
        );
    }

    /**
     * Parse a route path into its placeholders, left to right.
     *
     * `parent` is the name of the resource placeholder immediately in front of
     * this one — the only candidate for a parent, so that no parse can produce
     * a grandparent. `relation` is the literal segment directly in front, which
     * is the relation to reach the child through; it is null when the segment
     * in front is another placeholder, and for a placeholder that shares its
     * segment with other text and so occupies no position of its own.
     *
     * `unreadableParent` carries the segment that took the parent away, and is
     * the difference between "nothing is in front of this" and "something is in
     * front of this and it cannot be read". Both used to arrive as a null
     * parent, and the second one then resolved globally — `/u{user}/posts/{post}`
     * handed back any post at all, because `u{user}` never became a preceding
     * resource and nothing recorded that it had been skipped.
     *
     * `sharedSegment` is the same fact one step closer in: the thing in front of
     * this placeholder that cannot be read is inside its own segment. It is null
     * for the first placeholder of every segment and the segment itself for each
     * one after it, and it is a refinement of `unreadableParent` rather than an
     * alternative to it — both are set, so a caller that reads only the coarser
     * one still refuses. That is deliberate: the shape this distinguishes is the
     * one that spent longest resolving silently.
     *
     * @return list<array{
     *     name: string,
     *     key: string|null,
     *     relation: string|null,
     *     parent: string|null,
     *     unreadableParent: string|null,
     *     sharedSegment: string|null,
     * }>
     */
    private static function parsePlaceholders(string $path): array
    {
        $placeholders = [];
        $precedingLiteral = null;
        $precedingResource = null;
        $precedingUnreadable = null;

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                $precedingLiteral = null;
                continue;
            }

            $matchCount = preg_match_all(self::PLACEHOLDER_PATTERN, $segment, $matches, PREG_SET_ORDER);

            if ($matchCount === false || $matchCount === 0) {
                $precedingLiteral = $segment;
                continue;
            }

            // Only a segment that is nothing but one placeholder is a resource
            // placeholder. `v{version}`, `u{user}`, `{post}-x` and `{a}{b}` each
            // hold a placeholder without being one, and no property of the URL
            // says which of the two they are.
            $isResource = $matchCount === 1 && $matches[0][0] === $segment;

            // Null while reading the first placeholder of this segment, the
            // segment itself for every one after it. "After" is a fact about one
            // segment, so the variable lives and dies with one.
            $sharedSegment = null;

            foreach ($matches as $match) {
                $key = $match[2] ?? '';

                $placeholders[] = [
                    'name' => $match[1],
                    'key' => $key === '' ? null : $key,
                    'relation' => $isResource ? $precedingLiteral : null,
                    'parent' => $precedingResource,
                    'unreadableParent' => $precedingUnreadable,
                    'sharedSegment' => $sharedSegment,
                ];

                // Advanced HERE, once per placeholder, and not once per segment
                // after this loop. Advancing it afterwards handed every
                // placeholder in a segment the state from in front of the whole
                // segment, so on `/{user}-{post}` the post read "nothing is in
                // front of me" — which is the top-of-path state, which is a root
                // — and post 20 came back under a URL naming user 1.
                if ($isResource) {
                    $precedingResource = $match[1];
                    $precedingUnreadable = null;

                    continue;
                }

                // A segment that holds a placeholder without being one is not a
                // parent and does not let containment pass through it, and that
                // is as true for the rest of its own placeholders as for the
                // segments behind it. Three facts are recorded: the parent is
                // dropped, the segment that took it away is kept so the refusal
                // can name it, and the placeholders still to come in this
                // segment are marked as standing inside that segment rather
                // than behind it — a different mistake with a different fix.
                $precedingResource = null;
                $precedingUnreadable = $segment;
                $sharedSegment = $segment;
            }

            $precedingLiteral = null;
        }

        return $placeholders;
    }
}
