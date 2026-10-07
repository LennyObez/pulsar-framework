<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Supplies values for handler parameters the route alone cannot fill.
 *
 * The kernel asks every registered resolver once per handler invocation and
 * merges what they return. A resolver states BOTH facts in one value: the keys
 * of the returned map are the parameters it supplies, the values are what it
 * supplies. A key present with a null value is a claim of null — distinct from
 * no claim at all — so a resolver can legitimately supply null.
 *
 * The kernel never asks why. It knows nothing about models, services, request
 * bodies or validated payloads; it knows only that a name was claimed and a
 * value came back. Every parameter left unclaimed falls through to the
 * pre-existing behaviour: the route parameter of the same name, then the
 * declared default. A parameter no claim, no route parameter and no default can
 * fill is omitted, and the kernel keeps going — that is how controllers have
 * always been called and it does not change because a resolver is registered.
 *
 * WHAT A CLAIM BUYS IS DELIVERY BY NAME. Omitting a parameter shifts every
 * later argument one slot left, which is harmless while every value came from
 * the route or a default and is a misdelivery the moment a resolver's value is
 * in the list. So when a claimed value is in the argument list AND an omitted
 * parameter precedes some value, the kernel spreads the whole call as NAMED
 * arguments: every value lands on the parameter it was computed for, and the
 * omitted parameter raises an ArgumentCountError naming itself instead of
 * silently receiving its successor's value. A claim is therefore delivered to
 * the parameter it names or not at all — never to a different one.
 *
 * The corollary matters to anyone auditing a route: when no claimed value
 * reaches the argument list, the handler is called exactly as it was before
 * this seam existed, values and delivery mode alike.
 *
 * Contract for implementors:
 *  - Return an EMPTY array when there is nothing to supply. This is the common
 *    case and must be cheap: it runs on every request that reaches a handler.
 *  - Never return a key absent from `$signature->parameters`; it is ignored.
 *  - Claim a parameter only when the value is assignable to its declared type.
 *    The kernel does not type-check the value; PHP raises a TypeError at the
 *    call site, and the caller sees a 500 rather than a diagnosable failure.
 *  - Do not throw for "cannot supply". Return no claim. An exception here
 *    escapes into the kernel's dispatch error handling.
 *  - Judge each parameter on its own. A parameter you cannot supply says
 *    NOTHING about the ones after it: another resolver may supply it, and you
 *    cannot see the chain. Never stop claiming, or decline a claim, because
 *    some earlier parameter looked unfillable from the route — that reasoning
 *    turns your output into a function of an unrelated resolver's parameter
 *    being declared before yours. The kernel merges every claim before it
 *    decides what is fillable, and it is the only place that can decide.
 *  - Wrap the value in a {@see SealedArgument} when displacing it would change
 *    what the request is allowed to do, and only then. See below.
 *
 * ## Precedence
 *
 * For a parameter no two resolvers claim — the overwhelming majority — order
 * changes nothing: every resolver is asked the same question and the merge is
 * by key. Order is a question only where two resolvers name one parameter, and
 * there the answer depends on whether the value is sealed.
 *
 * An ORDINARY claim follows first-claim-wins: whichever registration ran first
 * decides the contested name. That is the right default for an interchangeable
 * value — a service, a deserialized body — where the two candidates are equally
 * legitimate and picking one is a composition preference.
 *
 * A SEALED claim ignores order. Wrap the value in a {@see SealedArgument} and it
 * displaces an ordinary claim made before it and blocks every ordinary claim
 * made after it, so the value reaches the handler wherever on the chain your
 * resolver happens to sit. Seal what would become UNSAFE rather than merely
 * different if another resolver won the name: a model an authorization hook
 * approved, a subject taken from a verified signature, a payload a policy
 * engine already ruled on.
 *
 * SEALING IS THE MECHANISM BECAUSE ORDER CANNOT BE ONE. An earlier revision of
 * this contract asked you to register such a resolver "ahead of anything that
 * could claim the same name", which was wrong twice over. It put a security
 * decision in the hands of whoever wires the application last, and the framework
 * could not follow its own advice: `ModelBindingWiring` registers the bound-model
 * resolver from `DeferredComposition`, at the end of boot, because the container
 * cannot answer whether an ORM is installed until every extension has registered
 * — so the framework's own resolver is always LAST on the chain and, under
 * first-claim-wins, always loses. Nothing an integrator does to the order can
 * fix that, and nothing an integrator does to the order can break sealing.
 *
 * Two resolvers sealing the same parameter is refused rather than ranked: the
 * chain throws {@see ConflictingSealedArgumentException} and the request fails
 * closed, because preferring the earlier seal would put registration order back
 * exactly where sealing removed it.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface HandlerArgumentResolverInterface
{
    /**
     * @param array<string, string> $routeParameters Raw, unconverted route parameters.
     *
     * @return array<string, mixed> Parameter name => value, for the parameters this
     *                              resolver supplies. Empty when it supplies none.
     *                              A value wrapped in {@see SealedArgument} is
     *                              delivered unwrapped and cannot be displaced by
     *                              another resolver's ordinary claim.
     */
    public function resolve(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array;
}
