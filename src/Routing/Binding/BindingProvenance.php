<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Routing\MatchedRoute;
use WeakMap;

use function strlen;

/**
 * The record of which objects this framework's own binding pipeline produced,
 * for which route parameter, for which URL value, and on which request.
 *
 * ## Why a seal needs this
 *
 * {@see BoundModelArgumentResolver} wraps every claim in a
 * {@see \Pulsar\Core\Controller\SealedArgument}, which no other resolver may
 * displace. Its evidence used to be the `_bound_models` request attribute and
 * nothing else — a plain array, on a request object every middleware in the
 * pipeline can rewrite. Anything that could set that attribute could therefore
 * put an object of its own on a handler parameter typed as an entity AND make it
 * undisplaceable, which is a stronger position than the authorized model itself
 * had before sealing existed. "Cannot be displaced" had quietly become
 * "trusted", on the strength of a value the framework never produced.
 *
 * So the resolver asks this class instead, and this class only ever answers yes
 * about an object {@see ModelBinder} handed back. Setting the attribute is no
 * longer enough: the object in it has to be one the framework resolved, through
 * the {@see Contract\ModelResolverPort} the application bound, under the
 * {@see ResolutionContext} the middleware built. And the binder records a level
 * only after the {@see BindingAuthorization} it was given has permitted it, so a
 * model {@see Contract\AuthorizationHookInterface} refused has no entry here
 * either — an entry means resolved by the framework and cleared by whatever
 * authorization the route's preset mandates.
 *
 * ## What an entry binds, and why it is not just "the framework made this"
 *
 * An entry names the route PARAMETER and the raw URL VALUE the object was
 * resolved for, so an attestation cannot be moved. Replaying an object minted
 * for `{post}` = `41` into a request whose `{post}` is `9` fails, and so does
 * replaying it onto a different parameter of the same route. What remains
 * replayable within one request is the object this very request would have
 * resolved anyway — and a request that reaches a handler at all is one whose own
 * binding pass resolved and authorized that same parameter and value, so the
 * replay wins nothing that was not already granted.
 *
 * ## An entry names the DISPATCH it was minted for
 *
 * Parameter and value are not enough on their own, because they are the two
 * things a forgery copies. {@see ModelBindingMiddleware::forDispatchedRoute()}
 * takes a {@see MatchedRoute}, and it is public because
 * {@see \Pulsar\Http\Middleware\DispatchedRouteAwareInterface} requires it to
 * be; the middleware is reachable from the container and, even unbound, from the
 * container-bound {@see \Pulsar\Http\Middleware\PostRoutingPipeline}'s public
 * `snapshot()`. So a frame inside the request could bind the binding middleware
 * to a route IT chose — one declaring the `_without_authorization` opt-out on a
 * `#[PublicRoute]` handler, say, which is a legal exemption on that route — copy
 * the real route's parameter name and value into the match, and mint a model no
 * policy was ever asked about. Every value-level check below then passed,
 * because the forgery had been built to satisfy exactly those checks.
 *
 * An entry therefore also names the MatchedRoute it was minted under, and
 * {@see attests()} answers only for the route it is asked about, by IDENTITY.
 * The route the question arrives with is the one the kernel is dispatching:
 * {@see \Pulsar\Core\Kernel::dispatchRoute()} restores its own MatchedRoute
 * onto the request at the handler frame, whatever any frame in between wrote
 * there, and {@see BoundModelArgumentResolver} reads it from there. Router
 * matching allocates a fresh object per match, so "the same object" means "this
 * dispatch" and nothing else.
 *
 * What that leaves standing is a mint made under the route the kernel really is
 * serving — which is the binding this request already earns, decided by that
 * route's own preset, opt-out and hook. Whether it was decided for the right
 * CALLER is a different question with a different answer, and this class is not
 * it: the identity the binding layer authorizes against still arrives on a
 * request attribute, so a frame able to call the middleware can also hand it an
 * identity of its choosing. Closing that is what moves identity off the request
 * and onto a channel the framework owns; until then the two halves of "the
 * handler's declaration decides" are closed one each.
 *
 * ## An entry is confined to the request that earned it
 *
 * Parameter and value alone left an attestation good for the life of the OBJECT,
 * and objects outlive requests: a persistence layer with a process-lifetime
 * identity map hands the same instance to every request that asks for that row.
 * A frame inside the pipeline could take a `Post` a colleague's earlier request
 * had resolved and authorized, put it in `_bound_models`, and have it sealed
 * onto a handler on a route this caller was never authorized for — most easily
 * on a route that binds nothing at all, where the binder never runs to contradict
 * it. The attestation would still be true, and it would still be about a
 * decision made for someone else.
 *
 * So an entry also names the PASS it was minted in, and {@see attests()} answers
 * only for the pass that is currently open.
 * {@see ModelBindingMiddleware::process()} opens one at the top of every request
 * it sees, before any early return, so a request that binds nothing still ends
 * the previous request's attestations.
 *
 * ### Why a counter and not something taken from the request
 *
 * Every obvious alternative is either unavailable or rewritable:
 *
 *  - The request OBJECT cannot identify a request. PSR-7 messages are immutable,
 *    so the request the binder saw and the request the argument resolver sees are
 *    different instances — `withAttribute()` returns a clone — and holding either
 *    identity would fail closed on every request.
 *  - A token carried in a request attribute is written on the same surface the
 *    seal exists because it cannot trust: the frames that can rewrite
 *    `_bound_models` can rewrite the token beside it.
 *  - {@see \Pulsar\Runtime\RequestResetRegistry} clears per-request state by
 *    container SERVICE ID, and this object is deliberately not a service.
 *
 * A counter held privately here is reachable from none of those surfaces. It
 * depends on one property of the runtime: that a worker handles one request at a
 * time, which ADR-0010 states ("individual request handling remains sequential")
 * and which the fiber concurrency in that ADR applies to accepting connections
 * rather than to serving them. Should that ever change, this counter has to
 * become per-context state before requests can overlap — two overlapping passes
 * would each end the other's attestations, and the seals would fail closed
 * rather than open, which is the direction to fail in but is still a break.
 *
 * ## Lifetime
 *
 * Keys are held weakly. An entry costs nothing once the model is collected, so a
 * resident worker accumulates no per-request residue, and a persistence layer
 * with a process-lifetime identity map keeps exactly the entries its own objects
 * keep alive — bounded, because an object minted in a new pass drops the entries
 * it carried from the old one instead of accumulating a set per request it has
 * lived through.
 *
 * ## Who can write, stated accurately
 *
 * Not "nobody". This object and the {@see ModelBinder} that writes it are kept
 * out of the container, and that is worth doing — it removes the one-line
 * `get(ModelBinder::class)->bindWithMeta(...)` mint — but it is not a boundary
 * and had no business being described as one. `Closure::bind()` reaches a
 * private property of any object that can be reached at all;
 * {@see ModelBindingMiddleware} IS bound in the container, and even unbound it
 * is handed back by {@see \Pulsar\Http\Middleware\PostRoutingPipeline::snapshot()},
 * which is public on a container-bound pipeline; and `process()` is public
 * because PSR-15 says so. Every attempt to close this by hiding the instance was
 * defeated one call deeper, because visibility was never what was holding.
 *
 * So the write side is reachable, and what stops a reachable mint being a useful
 * one is that an entry is worth nothing outside the dispatch that made it. An
 * attestation is credited only for the pass that is open AND the MatchedRoute
 * the kernel presents at the handler frame — neither of which a caller chooses,
 * because the kernel opens the one and restores the other after every frame has
 * had its turn. Minting under a route of one's own is still possible and buys
 * nothing; minting under the kernel's own route buys exactly the binding that
 * route already grants.
 */
#[Internal(reason: 'Provenance record shared by ModelBinder and BoundModelArgumentResolver; composed by ModelBindingWiring')]
final class BindingProvenance
{
    /**
     * Model instance => the pass and the dispatch it was last minted in, and the
     * set of "parameter + route value" pairs it was resolved for there.
     *
     * The MatchedRoute is held strongly for as long as the model key lives. That
     * is one small object per LIVE model, replaced whenever the model is minted
     * again, so a persistence layer with a process-lifetime identity map retains
     * one route reference per resident entity rather than one per request the
     * entity has lived through.
     *
     * @var WeakMap<object, array{pass: int, dispatch: MatchedRoute, entries: array<string, true>}>
     */
    private WeakMap $minted;

    /**
     * The pass {@see attests()} answers for.
     *
     * Starts at 1 rather than 0 so that "never opened" is still a pass a
     * hand-built binder can mint into and read back consistently, which is what
     * an application calling the binder outside a request gets.
     */
    private int $pass = 1;

    public function __construct()
    {
        // Through a local: `new WeakMap()` is `WeakMap<object, mixed>` to the
        // analysers, and narrowing it on the way in is what keeps every read
        // below checked rather than suppressed.
        /** @var WeakMap<object, array{pass: int, dispatch: MatchedRoute, entries: array<string, true>}> $minted */
        $minted = new WeakMap();

        $this->minted = $minted;
    }

    /**
     * Open a new pass, ending every attestation minted before it.
     *
     * Called once per request, from the top of
     * {@see ModelBindingMiddleware::process()}. Nothing is cleared here: entries
     * name the pass they were minted in, so the ones that no longer attest cost
     * a comparison rather than a sweep, and a model minted again in this pass
     * drops them.
     */
    public function beginRequest(): void
    {
        ++$this->pass;
    }

    /**
     * Record that the binding pipeline produced this model for this parameter,
     * in the pass that is open now.
     *
     * Called by {@see ModelBinder} for every model it resolves, whichever
     * resolver produced it — the default port or a per-binding custom one — so
     * that "the framework resolved this" has a single meaning.
     *
     * @param MatchedRoute $dispatch      The route the binder was resolving for.
     * @param string       $parameterName The route parameter the model was resolved for.
     * @param string       $routeValue    The raw, matched URL value that identified it.
     */
    public function record(object $model, MatchedRoute $dispatch, string $parameterName, string $routeValue): void
    {
        $recorded = $this->minted[$model] ?? null;

        // Entries from an earlier pass, or minted under another route, are not
        // evidence any more, so they are dropped rather than carried: that keeps
        // this map's size a function of what the CURRENT dispatch bound, not of
        // everything a long-lived object has ever been bound as.
        $entries = $recorded !== null && $recorded['pass'] === $this->pass && $recorded['dispatch'] === $dispatch
            ? $recorded['entries']
            : [];

        $entries[self::entry($parameterName, $routeValue)] = true;

        $this->minted[$model] = ['pass' => $this->pass, 'dispatch' => $dispatch, 'entries' => $entries];
    }

    /**
     * Whether this model was resolved by the binding pipeline for this exact
     * parameter and URL value, under this exact dispatch, in the pass that is
     * open now.
     *
     * False for anything the framework did not produce, which is the whole
     * point: an object a request attribute merely names has no entry here. False
     * too for an object the framework produced for an earlier request, however
     * legitimately — the decision that minted it was made about a caller who is
     * no longer the one asking. And false for an object minted under a route
     * this dispatch is not serving, which is the one a frame inside the request
     * gets to choose.
     *
     * $dispatch must be the route the KERNEL is dispatching, not one read off
     * `_route` by a frame that may have been handed a substitute. The only
     * caller is {@see BoundModelArgumentResolver}, which runs in the handler
     * frame, where {@see \Pulsar\Core\Kernel::dispatchRoute()} has just put its
     * own MatchedRoute back on the request.
     */
    #[NoDiscard]
    public function attests(object $model, MatchedRoute $dispatch, string $parameterName, string $routeValue): bool
    {
        $recorded = $this->minted[$model] ?? null;

        if ($recorded === null || $recorded['pass'] !== $this->pass || $recorded['dispatch'] !== $dispatch) {
            return false;
        }

        return isset($recorded['entries'][self::entry($parameterName, $routeValue)]);
    }

    /**
     * Length-prefix the parameter name so no separator can be forged.
     *
     * A plain `name:value` join lets a route value carrying the separator
     * impersonate another parameter's entry; the length pins where the name ends
     * before the value is read at all.
     */
    private static function entry(string $parameterName, string $routeValue): string
    {
        return strlen($parameterName) . ':' . $parameterName . ':' . $routeValue;
    }
}
