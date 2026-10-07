<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Core\Controller\HandlerArgumentResolverInterface;
use Pulsar\Core\Controller\HandlerSignature;
use Pulsar\Core\Controller\SealedArgument;
use Pulsar\Routing\MatchedRoute;

use function is_array;
use function is_object;

/**
 * Supplies handler parameters from the models {@see ModelBindingMiddleware}
 * resolved and authorized earlier in the request.
 *
 * The middleware owns resolution, authorization and the HTTP failure modes;
 * this class only hands the already-vetted objects to the handler. It never
 * resolves anything itself, so a request that did not pass through the
 * middleware — or an application with no ModelResolverPort — claims nothing
 * and the kernel behaves exactly as it did before route model binding existed.
 *
 * It claims each parameter on its own evidence and draws no conclusion from a
 * parameter it cannot claim. A parameter it does not supply may still be
 * supplied by another resolver on the chain, and this class cannot see the
 * chain: the kernel merges every claim before deciding what is fillable. An
 * earlier version stopped claiming at the first parameter absent from the route
 * parameters, which made a bound model's claim depend on whether some other
 * resolver's parameter happened to be declared before it — registering a second
 * resolver then dropped the model and spread the raw route string into a
 * parameter typed as an entity.
 *
 * ## Every claim is sealed
 *
 * The objects handed over here were loaded, tenant-scoped and passed through an
 * {@see Contract\AuthorizationHookInterface} by {@see ModelBindingMiddleware}
 * before this resolver ran. A second resolver claiming the same parameter name
 * does not offer a different value for it, it offers an UNAUTHORIZED one — on
 * the parameter whose entity type hint is the very thing that makes the route
 * read as safe. So each claim is wrapped in a {@see SealedArgument}, which the
 * chain refuses to let an ordinary claim displace.
 *
 * That has to be a property of the claim rather than of this resolver's position,
 * because the position is fixed against us: {@see \Pulsar\Core\Wiring\ModelBindingWiring}
 * registers this resolver from {@see \Pulsar\Core\Boot\DeferredComposition}, at
 * the very end of boot, since nothing earlier can tell whether a persistence
 * extension bound a {@see Contract\ModelResolverPort}. Under plain
 * first-claim-wins this resolver is therefore always LAST on the chain, and any
 * resolver an application or extension registered — from a service provider, an
 * extension `boot()`, a project bootstrap — took the name and the authorized
 * model never reached the handler.
 *
 * ## Where the value came from is part of what is sealed
 *
 * The `_bound_models` attribute is how the middleware hands its work over, and
 * it is also ordinary request surface: every middleware inner to the binding one
 * can rewrite it, and so can anything else holding the request. Sealing on that
 * evidence alone made the seal claim more than the middleware ever had — it
 * protected a value from displacement while saying nothing about where the value
 * came from, so whoever could set one attribute got their object onto an
 * entity-typed parameter AND made it undisplaceable. "Cannot be displaced" is
 * not "was authorized", and only one of the two had been earned.
 *
 * So a claim needs both halves now. The attribute still says WHICH parameter a
 * model is offered for; {@see BindingProvenance} — written by {@see ModelBinder}
 * as it resolves, and reachable from no request — says whether the framework
 * produced that exact object for that exact parameter and URL value. The binder
 * mints a level only after the authorization gate it was given has passed it, so
 * an entry is evidence that the model was resolved by the framework AND cleared
 * whatever authorization this route's preset mandates: a model the hook refused
 * is never minted, and a route the preset authorizes nothing on is the one
 * {@see BindingPreset::Standard} documents as such.
 *
 * ## The dispatch the attestation is checked against comes from the kernel
 *
 * Provenance answers about a route as well as a parameter and a value, and the
 * route this resolver asks with is `_route` — read HERE and nowhere earlier,
 * because here is the one frame where the attribute is not ordinary request
 * surface. {@see \Pulsar\Core\Kernel::dispatchRoute()} restores its own
 * {@see MatchedRoute} onto the request on the way into the handler frame,
 * undoing anything the post-routing stack wrote, and then resolves the handler's
 * arguments from that same request. So the object this class hands to
 * {@see BindingProvenance::attests()} is the route whose handler is about to
 * run, whatever any middleware claimed in between.
 *
 * That is what makes the parameter-and-value check hold up against a forgery
 * built to satisfy it. {@see ModelBindingMiddleware::forDispatchedRoute()} is
 * public — PSR-15 and {@see \Pulsar\Http\Middleware\DispatchedRouteAwareInterface}
 * both require it to be — and the middleware is reachable, so a frame inside the
 * request can bind it to a route of its own and mint a model for the real
 * route's parameter name and value. Everything about such a model passes the two
 * checks above; only the route it was minted under gives it away.
 *
 * A request with no `_route` claims nothing. That is not a scenario the kernel
 * produces — it writes the attribute before any middleware runs and restores it
 * before this frame — so the branch exists for a chain assembled by something
 * other than the kernel, where there is no dispatch to attest to.
 *
 * An object with no entry is not claimed at all. It is not sealed, and it is not
 * offered unsealed either: handing a handler an unvetted object on a parameter
 * typed as an entity is the outcome the seal exists to prevent, not a lesser
 * version of it. The parameter then falls through to the kernel's
 * route-parameter and default ladder like any other unclaimed parameter, so a
 * controller typed on the entity fails loudly rather than receiving something
 * that merely looks like one.
 */
#[Internal(reason: 'Registered on the kernel argument-resolver chain by ModelBindingWiring')]
final readonly class BoundModelArgumentResolver implements HandlerArgumentResolverInterface
{
    /**
     * @param BindingProvenance $provenance
     *        The record of what {@see ModelBinder} resolved. It has to be the
     *        instance that binder writes to — {@see \Pulsar\Core\Wiring\ModelBindingWiring}
     *        composes both halves from one object — because a resolver holding
     *        any other instance attests to nothing and therefore seals nothing.
     */
    public function __construct(private BindingProvenance $provenance) {}

    /**
     * @param array<string, string> $routeParameters
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function resolve(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array {
        /** @var mixed $bound */
        $bound = $request->getAttribute('_bound_models');

        if (!is_array($bound) || $bound === []) {
            return [];
        }

        // The dispatched route, restored by the kernel on the way into this
        // frame. See the class docblock for why reading it here is safe and
        // reading it anywhere earlier is not.
        /** @var mixed $dispatch */
        $dispatch = $request->getAttribute('_route');

        if (!$dispatch instanceof MatchedRoute) {
            return [];
        }

        $claimed = [];

        foreach ($signature->parameters as $parameter) {
            /** @var mixed $model */
            $model = $bound[$parameter->name] ?? null;

            // Claim only when the resolved model actually satisfies the declared
            // type, and ask the declaration itself rather than rebuilding the
            // question out of a single class name. A route parameter whose name
            // collides with a scalar handler argument must not hand the handler
            // an object it never asked for: PHP would raise a TypeError at the
            // call site and the caller would see a 500 in place of a working
            // route.
            //
            // The other direction was a hole. A parameter typed `Post|string`
            // DID ask for the entity, but HandlerParameter::$type is null for a
            // union, so `$parameter->builtin ? null : $parameter->type` answered
            // "no type" — indistinguishable from an untyped parameter. The
            // resolved, authorized Post was dropped, the kernel filled the slot
            // from the route parameters instead, and the handler received the
            // raw URL string where an entity had been declared acceptable.
            if (!is_object($model) || !$parameter->accepts($model)) {
                continue;
            }

            // The URL value this parameter matched, which is what the binder
            // resolved the model FROM. A parameter the route did not supply
            // cannot have been bound, so there is no attestation to check it
            // against and nothing here to claim.
            $routeValue = $routeParameters[$parameter->name] ?? null;

            if ($routeValue === null) {
                continue;
            }

            // Provenance, asked per parameter rather than per request: an
            // attestation is bound to the parameter name and the URL value it
            // was minted for, so a model the framework produced for one
            // parameter cannot be presented as another's, and one produced for
            // another id cannot be presented as this one's. It is bound to the
            // DISPATCH as well, so a model minted under a route this kernel is
            // not serving is not presentable as this route's at all.
            if (!$this->provenance->attests($model, $dispatch, $parameter->name, $routeValue)) {
                continue;
            }

            $claimed[$parameter->name] = new SealedArgument($model);
        }

        return $claimed;
    }
}
