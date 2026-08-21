<?php

declare(strict_types=1);

/**
 * Route Model Binding
 * --------------------------------------------------------------------------
 *
 * Turns a route parameter into the domain object it names: `/users/{user}`
 * hands the controller a User, already loaded, already authorized, already
 * constrained to the current tenant.
 *
 * The feature is OPT-IN by installation, not by a flag here. Nothing in this
 * file switches it on: the binding pipeline composes only when a persistence
 * extension has bound a `Pulsar\Routing\Binding\Contract\ModelResolverPort`
 * (pulsar/orm does) AND an authorization hook is available. With neither, this
 * file is read, its settings are published, and no middleware is piped — the
 * application boots exactly as it would without it.
 *
 * PRECEDENCE. Once the host publishes this section, it is the single source of
 * truth for every participant. `pulsar/orm` reads the host's
 * `ModelBindingConfig` in preference to its own `model_binding` extension
 * section, deliberately: the two halves of the feature enforce different keys of
 * this file — the middleware enforces `preset`, the resolver enforces
 * `allowed_key_names` — so a second copy would not be a duplicate, it would be a
 * different policy applied at the other half. If you previously configured
 * `model_binding` in the ORM extension config, move those values here.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Preset
    |--------------------------------------------------------------------------
    |
    | Two behaviours under four names.
    |
    | 'banking' | 'healthcare' | 'legal' — regulated. Authorization is MANDATORY
    |               on every bound model: an unauthenticated request to a bound
    |               route is a 401, a failed policy is a 403, and the
    |               '_without_authorization' route attribute is refused on any
    |               route that is not explicitly #[PublicRoute]. That opt-out is
    |               a route attribute and nothing else — no attribute class and
    |               no router method for it exist.
    |
    | 'standard'  — authorization is opt-in. A request with no authenticated
    |               identity binds the model and hands it to the controller with
    |               no policy check at all.
    |
    | The three regulated names differ in NO behaviour: `preset` reaches code
    | only through ModelBindingConfig::isRegulatedPreset(), never an audit record
    | or a response, so the name documents which regime you answer to and the
    | value picks one of the two enforcement levels.
    |
    | THESE FOUR NAMES ARE THE WHOLE SET. Anything else here — 'Banking',
    | 'bankng', true, null — is REFUSED during config load and the application
    | does not boot. It used to be read as 'standard', because an unrecognized
    | preset and a deliberately permissive one were the same value to the code
    | that checked it, which made this line's security posture depend on its
    | spelling and fail open when the spelling was wrong. There is no value of
    | this setting that means "I do not know": the two things it could fall back
    | to differ in whether a caller the framework cannot identify is handed the
    | model. The names live in Pulsar\Routing\Binding\BindingPreset.
    |
    | WHY THE DEFAULT IS REGULATED. This framework is built for banking,
    | healthcare and legal work, and one frame away sits an AuthorizationMiddleware
    | that refuses a route merely for declaring no permission — a binding layer
    | that default-allows next to it is a trap, because the two would disagree
    | about the same request while both looked configured. Shipping 'banking' as
    | the label is the arbitrary part and the only part a reviewer should argue
    | about: rename it to the regime you actually answer to, which never weakens
    | enforcement. Only 'standard' does, and that edit is one grep away.
    |
    | Moving an existing application UP to a regulated preset will start
    | returning 401 on bound routes that have no authenticated caller and 403 on
    | '_without_authorization' routes that are not #[PublicRoute]. That is the
    | point; audit those routes rather than reaching for 'standard'.
    |
    | DELETING THIS FILE DOES NOT WEAKEN ANYTHING. An absent
    | config/model_binding.php is not an error: ModelBindingWiring falls back to
    | the ModelBindingConfig constructor defaults, and that default is
    | ModelBindingConfig::DEFAULT_PRESET — a regulated one, the same enforcement
    | level this file ships. It used to be 'standard', so this file and the code
    | behind it disagreed and the permissive posture was reached by deleting a
    | file rather than by editing one. Saying nothing is not a decision, and it
    | no longer selects the weaker of the two levels. The refusal above catches a
    | preset written down wrong; the constructor default catches one that is not
    | written down at all.
    |
    */
    'preset' => 'banking',

    /*
    |--------------------------------------------------------------------------
    | Authorization Hook
    |--------------------------------------------------------------------------
    |
    | Class implementing Pulsar\Routing\Binding\Contract\AuthorizationHookInterface,
    | asked to approve every resolved model before the controller sees it.
    |
    | Null uses the framework default, Pulsar\Routing\Binding\PolicyAuthorizationHook,
    | which delegates to the authorization Gate with the permission the binding
    | declares (defaulting to 'view').
    |
    | The default needs a Gate, which AuthWiring binds from the `auth` section of
    | config/security.php. With no Gate and no hook named here, route model
    | binding does NOT compose — see the class docblock of
    | Pulsar\Core\Wiring\ModelBindingWiring for why an "allow everything" hook is
    | not offered as a fallback.
    |
    */
    'authorization_hook' => null,

    /*
    |--------------------------------------------------------------------------
    | Allowed Key Names
    |--------------------------------------------------------------------------
    |
    | The complete set of columns a route may look a model up by, via the
    | `/users/{user:slug}` custom-key syntax.
    |
    | WHERE IT IS ENFORCED: in the ModelResolverPort adapter, immediately before
    | it builds SQL, and NOWHERE ELSE. `pulsar/orm` refuses a key name that is
    | not on this list, is not a valid identifier, maps to no column on the
    | entity, or maps to an encrypted column — each as a 400. The binding
    | middleware does not check the list; it passes the route-supplied key name
    | straight through. So a custom ModelResolverPort inherits no protection: if
    | you write one, read ModelBindingConfig::$allowedKeyNames and apply it
    | yourself before the name reaches a query.
    |
    | Keep it short and keep it non-secret. Every name added here becomes a
    | column an unauthenticated caller can probe for existence by URL. Keep `id`
    | on the list: implicit binding emits that key name for every parameter, so
    | removing it turns every implicit binding in the application into a 400.
    |
    */
    'allowed_key_names' => ['id', 'uuid', 'slug'],

    /*
    |--------------------------------------------------------------------------
    | Compiled Mode
    |--------------------------------------------------------------------------
    |
    | RESERVED. Nothing reads this key. ModelBindingConfig stores it and no other
    | class in src/, extensions/ or tools/ ever looks at it, so setting it true
    | changes no behaviour whatsoever.
    |
    | What it was meant to select happens anyway: BindingResolver uses a
    | CompiledBindingMap whenever one is bound in the container, and reflects the
    | controller signature when one is not. Nothing in the framework builds such
    | a map today — `pulsar optimize` caches routes, config and container hints,
    | and no binding map — so the reflecting path is the only path a normal boot
    | takes. It reflects once per route shape per process and memoises the
    | answer.
    |
    */
    'compiled_mode' => false,
];
