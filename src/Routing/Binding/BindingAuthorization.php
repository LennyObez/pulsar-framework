<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Routing\MatchedRoute;

/**
 * The authorization decision {@see ModelBinder} binds a route under.
 *
 * ## Why this is a type and not a nullable closure
 *
 * The binder used to take `?Closure $authorize = null` and mint a
 * {@see BindingProvenance} entry for every model it resolved, gate or no gate.
 * Those two facts together are an authorization laundry: provenance is the
 * evidence {@see BoundModelArgumentResolver} seals a handler argument on, and it
 * was produced whether or not anything had decided the caller may have the row.
 * Every way of reaching the binder without a gate — a default argument nobody
 * passed, a hand-built binder, a caller who simply forgot — produced a model
 * carrying the same attestation an authorized one carries.
 *
 * A required `Closure` alone does not fix that, because the honest value for the
 * routes that legitimately authorize nothing would be `static fn() => true`,
 * which is the absence of a decision wearing the shape of one. So the parameter
 * takes a DECISION instead: either a gate, or a named exemption that says which
 * declaration exempts this route. There is no way to spell "I did not think
 * about it".
 *
 * ## What each exemption has to prove
 *
 * Both exemptions verify their own precondition rather than trusting the caller
 * to have checked it:
 *
 *  - {@see declaredWithoutAuthorization()} requires the route to actually carry
 *    the `_without_authorization` attribute. Route attributes come from the
 *    route table, which is written where the application registers its routes,
 *    so this is a declaration a reviewer can grep for and a request cannot
 *    introduce.
 *  - {@see unenforcedPreset()} requires a preset that does not mandate
 *    authorization. Under a regulated preset it refuses.
 *
 * What follows is the property the laundering broke: under a regulated preset,
 * for a route that does not declare the opt-out, the only decision that can be
 * constructed is {@see gate()}. "Resolved but never authorized" is not
 * expressible for such a route, rather than being merely discouraged.
 *
 * The remaining half of the check stays with
 * {@see ModelBindingMiddleware::decideAuthorization()}, which is the only place
 * that builds these: whether the opt-out is LEGAL — a regulated preset honours
 * it only on a `#[PublicRoute]` handler — is a policy question about the
 * application, not a fact about the route object, and splitting it here would
 * put a second, quieter copy of that policy in the framework.
 *
 * ## Pinned to the route it was decided for
 *
 * A decision names the {@see MatchedRoute} it was made about, and
 * {@see ModelBinder::bindWithMeta()} refuses one made about a different route.
 * Without that, an exemption legally obtained for a public route would be a
 * value that binds any route at all, and the object would be a bearer token for
 * skipping authorization.
 */
#[Internal(reason: 'Argument type of ModelBinder::bind()/bindWithMeta(); constructed by ModelBindingMiddleware')]
final readonly class BindingAuthorization
{
    /**
     * Route attribute that opts a route out of model authorization.
     *
     * Declared here because this is the class that verifies it: the attribute is
     * the whole evidence for {@see declaredWithoutAuthorization()}, and a second
     * spelling of the string is a second place for it to drift out of step with
     * the check.
     */
    public const string WITHOUT_AUTHORIZATION_ATTRIBUTE = '_without_authorization';

    /**
     * @param MatchedRoute $route The route this decision was made about.
     * @param (Closure(object, BindingMeta, string): bool)|null $gate
     *        The policy check to run on each resolved model, or null when
     *        {@see $basis} names the declaration that exempts this route.
     * @param string $basis Why there is no gate; the empty string when there is one.
     */
    private function __construct(
        private MatchedRoute $route,
        private ?Closure $gate,
        public string $basis,
    ) {}

    /**
     * Every model this route resolves is decided by `$gate`.
     *
     * The gate takes the model, the metadata that bound it and the parameter
     * name, and answers a single bool. It sees no request and no identity: those
     * belong to whoever builds the closure.
     *
     * @param (Closure(object, BindingMeta, string): bool) $gate
     */
    #[NoDiscard]
    public static function gate(MatchedRoute $route, Closure $gate): self
    {
        return new self($route, $gate, '');
    }

    /**
     * This route declares `_without_authorization`, and the caller has already
     * decided the declaration is legal here.
     *
     * Legality is the preset's question — a regulated preset honours the opt-out
     * only on a `#[PublicRoute]` handler — and it stays with the middleware. What
     * this constructor will not do is take the caller's word for the DECLARATION:
     * a route with no such attribute cannot be exempted by it.
     *
     * @throws ModelBindingException When the route does not declare the opt-out.
     */
    #[NoDiscard]
    public static function declaredWithoutAuthorization(MatchedRoute $route): self
    {
        if (($route->getAttributes()[self::WITHOUT_AUTHORIZATION_ATTRIBUTE] ?? false) !== true) {
            throw ModelBindingException::undeclaredAuthorizationOptOut(
                $route->getName() ?? $route->route->path,
            );
        }

        return new self($route, null, 'route_declared_without_authorization');
    }

    /**
     * The configured preset does not mandate authorization, and there is nobody
     * to authorize against.
     *
     * {@see BindingPreset::Standard} documents this posture: the model is handed
     * to a caller the framework cannot identify. It is a configuration choice,
     * written where a reviewer can find it, and it is refused outright for the
     * presets that mandate authorization.
     *
     * @throws ModelBindingException When the preset mandates authorization.
     */
    #[NoDiscard]
    public static function unenforcedPreset(MatchedRoute $route, BindingPreset $preset): self
    {
        if ($preset->isRegulated()) {
            throw ModelBindingException::authorizationMandatory(
                $preset->value,
                $route->getName() ?? $route->route->path,
            );
        }

        return new self($route, null, 'preset_does_not_enforce_authorization');
    }

    /**
     * Whether this decision was made about `$route`.
     *
     * Object identity, not path equality: the binder is handed the very match
     * the decision was made from, and two matches of the same path can carry
     * different parameters, attributes and handlers.
     */
    #[NoDiscard]
    public function appliesTo(MatchedRoute $route): bool
    {
        return $this->route === $route;
    }

    /**
     * Whether this caller may have this model.
     *
     * True without a gate only for the exemptions above, each of which had to
     * prove its own declaration. {@see ModelBinder} mints an attestation for a
     * model only after this has answered true for it, so an entry in
     * {@see BindingProvenance} means a decision was reached — not that one was
     * skipped.
     */
    #[NoDiscard]
    public function permits(object $model, BindingMeta $meta, string $parameterName): bool
    {
        if ($this->gate === null) {
            return true;
        }

        return ($this->gate)($model, $meta, $parameterName);
    }
}
