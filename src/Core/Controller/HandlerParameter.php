<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Pulsar\Api\Api;
use Traversable;

use function in_array;
use function is_callable;

/**
 * One parameter of a routed handler, reflected once and memoised.
 *
 * Plain data by construction: no closures, no reflection objects, nothing that
 * cannot be var_export()ed. That is deliberate — the memoised map of these is
 * exactly the artifact `pulsar optimize` can precompute into the framework
 * cache, at which point the kernel does no reflection at all.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class HandlerParameter
{
    /**
     * The builtin type names an object can satisfy.
     *
     * The language defines this set, and it is short: `object` and `mixed`
     * accept every object, `iterable` accepts a Traversable one and `callable`
     * accepts one with `__invoke`. Every other builtin — `string`, `int`,
     * `float`, `bool`, `array`, `false`, `true`, `null` — accepts no object at
     * all.
     *
     * It is a constant rather than four cases inside {@see nameAccepts()}
     * because two different layers ask the same question of it, and asking it
     * twice is how they came to disagree. {@see nameAccepts()} asks "does this
     * declaration accept THIS object" on the resolver side;
     * {@see builtinCanHoldObject()} asks "can this declaration ever hold an
     * object" on the binding side, where no object exists yet to test.
     * {@see \Pulsar\Routing\Binding\BindingResolver} used to answer the second
     * question with `ReflectionNamedType::isBuiltin()`, which says `object`,
     * `mixed`, `iterable` and `callable` are not object-bearing — so a
     * parameter declared with one of them bound no model, and on a regulated
     * preset that skipped the identity requirement and the authorization hook
     * with it.
     *
     * @var list<string>
     */
    private const array OBJECT_BEARING_BUILTINS = ['mixed', 'object', 'iterable', 'callable'];

    /**
     * @param string      $name       Declared parameter name (matches a route parameter when one exists).
     * @param string|null $type       Fully-qualified class/interface name, or a builtin type name.
     *                                Null when the parameter is untyped, or typed with a union or
     *                                intersection — read {@see $typeAlternatives} for those.
     * @param bool        $builtin    True when $type names a builtin (string, int, array, ...).
     * @param bool        $hasDefault Whether the declaration carries a default value.
     * @param mixed       $default    The default value, or null when there is none.
     * @param list<list<string>> $typeAlternatives
     *        The declaration in disjunctive normal form: a list of alternatives, each an
     *        inner list of type names a value must satisfy ALL of. `Post` is
     *        `[['Post']]`, `Post|string` is `[['Post'], ['string']]`, `Post&Countable` is
     *        `[['Post', 'Countable']]`, and `(Post&Countable)|string` is
     *        `[['Post', 'Countable'], ['string']]`. Empty for an untyped parameter, and
     *        empty on an instance built by hand from `$type` alone — {@see accepts()}
     *        falls back to `$type` in that case, so an older four-field construction keeps
     *        the behaviour it had.
     */
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $builtin,
        public bool $hasDefault,
        public mixed $default,
        public array $typeAlternatives = [],
    ) {}

    /**
     * Whether this parameter's declared type accepts the given object.
     *
     * The question a resolver actually has, asked in one place so that no
     * resolver has to reconstruct it from {@see $type} and {@see $builtin} — and
     * so that a declaration those two cannot express does not read as "accepts
     * nothing".
     *
     * That reading was a hole. `$type` is null for a union, so
     * `show(Post|string $post)` on a bound route looked to
     * {@see \Pulsar\Routing\Binding\BoundModelArgumentResolver} exactly like an
     * untyped parameter: the resolved, authorized Post was not claimed, the
     * kernel filled the slot from the route parameters instead, and the handler
     * received the raw URL string where it had declared that an entity was
     * acceptable. Nothing failed, nothing logged, and the containment and policy
     * checks that had just run were thrown away.
     *
     * A union accepts the object when ANY alternative does; an intersection when
     * EVERY name in it matches.
     *
     * ## "Builtin" is not the same as "no object"
     *
     * Both branches used to answer the question with `instanceof` alone, and the
     * single-name branch additionally refused anything {@see $builtin} flagged.
     * That reasoning is right for `string`, `int` and `array` and wrong for the
     * four builtin names that DO accept objects — two of them every object there
     * is:
     *
     *  - `object` accepts every object by definition.
     *  - `mixed` accepts every value, objects included.
     *  - `iterable` is `array|Traversable`, so it accepts a Traversable object.
     *  - `callable` accepts an object PHP can call, i.e. one with `__invoke`.
     *
     * `instanceof` cannot see any of that: none of those names is a class, so
     * `$value instanceof 'object'` is false rather than an error, and a handler
     * declaring `show(object $post)` — the shape a controller uses when it
     * accepts any entity, or when the entity class is generated — looked to
     * {@see \Pulsar\Routing\Binding\BoundModelArgumentResolver} exactly like a
     * parameter that accepts nothing. The resolved, authorized model was
     * dropped, the kernel filled the slot from the route parameters, and the
     * handler got the raw URL string in a slot that accepts literally anything.
     *
     * The remaining builtins still match no object, and they are matched by the
     * same `instanceof` fallthrough rather than by a list this class would have
     * to keep in step with the language.
     */
    public function accepts(object $value): bool
    {
        if ($this->typeAlternatives === []) {
            return $this->type !== null && self::nameAccepts($this->type, $value);
        }

        foreach ($this->typeAlternatives as $alternative) {
            if ($alternative === []) {
                continue;
            }

            foreach ($alternative as $typeName) {
                if (!self::nameAccepts($typeName, $value)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Whether a builtin type NAME can be satisfied by an object at all.
     *
     * The question a layer asks when it has a declaration and no value: a route
     * binder deciding whether a parameter could receive a model has not resolved
     * one yet, so {@see accepts()} — which needs the object — cannot answer it.
     *
     * Answering "can this hold an object" with `isBuiltin()` is what
     * {@see \Pulsar\Routing\Binding\BindingResolver} did, and the four names in
     * {@see OBJECT_BEARING_BUILTINS} are exactly where that answer is wrong.
     *
     * It is `false` for every other name, INCLUDING a class or interface name.
     * That is not an oversight and it is why the method says `builtin`: a name
     * that resolves to a class answers this question by resolving to a class,
     * and both callers below already treat it that way — the binder by naming
     * it as the model to resolve, {@see nameAccepts()} by testing `instanceof`.
     * Asking this about a class name means asking the wrong question, and the
     * answer it gets back keeps the caller on the branch that asks the right
     * one.
     */
    public static function builtinCanHoldObject(string $typeName): bool
    {
        return in_array($typeName, self::OBJECT_BEARING_BUILTINS, true);
    }

    /**
     * Whether one declared type NAME accepts the given object.
     *
     * Asked per name so a union and a single-name declaration answer identically,
     * and so an intersection member is judged by the same rule as a standalone
     * type. {@see $builtin} is deliberately not consulted: the flag says how the
     * name was declared, not what it accepts, and the object-bearing builtins are
     * builtin AND accept objects.
     */
    private static function nameAccepts(string $typeName, object $value): bool
    {
        if (!self::builtinCanHoldObject($typeName)) {
            // Every class, interface and the remaining builtins. A builtin name
            // is not a class, so this is false for `string`, `int`, `array` and
            // the rest rather than an error.
            return $value instanceof $typeName;
        }

        return match ($typeName) {
            'iterable' => $value instanceof Traversable,
            'callable' => is_callable($value),
            // `object` and `mixed`, and anything a future PHP adds to the
            // object-bearing set without a narrowing rule of its own: the widest
            // reading is the safe one for a declaration that admits objects.
            default => true,
        };
    }
}
