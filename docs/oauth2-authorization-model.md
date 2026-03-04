# OAuth2 authorization model

This document explains how Pulsar derives an authorisation decision from an OAuth2 access token. Closes audit finding **F385.10**.

## TL;DR

OAuth2-authenticated identities are **scope-based**, not role-based.

`OAuth2TokenResolver` produces an `Identity` whose `roles` are intentionally empty. Authorisation policies that operate on OAuth2 grants must read `Identity::attribute('oauth2_scopes')` (a `list<string>` of granted OIDC scopes) instead of `Identity::roles()`.

## Why empty `roles`

`Identity::roles` is the framework's role-based access control axis. Local-account policies (`UserService` etc.) populate it from the application's own user store: a user has the `editor` role because the operator marked them as such in the admin UI.

OAuth2 / OIDC tokens come from an external IdP. The IdP does not know about the relying party's role taxonomy — it knows about *scopes* (`profile`, `email`, `payments:write`, `admin:audit`, …). Mapping IdP scope → RP role is a per-deployment decision: `payments:write` might mean `cashier` for one tenant and `treasurer` for another. Forcing every consumer through a synthetic role mapping at the resolver level would either:

- be too lenient (one-fits-all `oauth2_user` role grants nothing useful), or
- be too coercive (custom mapping logic baked into the framework would need extension hooks for every relying party).

Leaving `roles: []` is an explicit decision: **OAuth2 grants assert authentication and scopes, not framework-internal roles.**

## Identity attributes populated by OAuth2TokenResolver

| Attribute | Type | Source |
|---|---|---|
| `oauth2_client_id` | `string` | `AccessToken::clientId` — the OAuth2 client that requested the grant. |
| `oauth2_scopes` | `list<string>` | `AccessToken::scopes` — the granted OIDC scopes (`openid`, `profile`, …). |
| `oauth2_token_id` | `string` | `AccessToken::id` — the introspection identifier. |

`twoFactorStatus` is derived from the token's `amr` / `acr` claims (RFC 8176 / OIDC Core 5.1.1.1, see [`OAuth2TokenResolver::deriveTwoFactorStatus()`](../extensions/oauth2/src/Adapter/OAuth2TokenResolver.php) and audit finding F385.7).

## Writing scope-aware authorisation policies

The framework's policy interface (`Pulsar\Auth\Authorization\PolicyInterface`) receives the resolved `IdentityInterface`. A scope-aware policy reads the granted scopes from the attributes bag:

```php
final readonly class WireTransferPolicy implements PolicyInterface
{
    public function __construct(private string $requiredScope) {}

    public function permits(IdentityInterface $identity, mixed $resource = null): bool
    {
        $scopes = $identity->attribute('oauth2_scopes');

        if (! is_array($scopes)) {
            // Not an OAuth2 grant — fall back to role-based check.
            return in_array('treasurer', $identity->roles(), true);
        }

        return in_array($this->requiredScope, $scopes, true);
    }
}
```

The `is_array($scopes)` discriminator distinguishes OAuth2 grants (scope-based) from local-account identities (role-based). The framework's `LevelOfAssuranceMiddleware` already handles the MFA dimension via `Identity::twoFactorStatus()`; combine the two checks for compound requirements ("scope `payments:write` AND `TwoFactorStatus::Verified`").

## Compounding with role-based policies

Some deployments map OAuth2 grants onto framework roles via a custom resolver. Wrap `OAuth2TokenResolver` in a decorator that consults the application's user store and overlays roles:

```php
final readonly class RoleEnrichingTokenResolver implements TokenResolverInterface
{
    public function __construct(
        private TokenResolverInterface $inner,
        private UserRoleLookup $userLookup,
    ) {}

    public function resolve(string $token): ?IdentityInterface
    {
        $identity = $this->inner->resolve($token);

        if ($identity === null) {
            return null;
        }

        $roles = $this->userLookup->rolesFor($identity->id());

        // Identity is readonly; produce a new instance with merged roles.
        return new Identity(
            id: $identity->id(),
            displayName: $identity->displayName(),
            roles: $roles,
            twoFactorStatus: $identity->twoFactorStatus(),
            attributes: $identity->attributes(),
        );
    }
}
```

This is opt-in. The framework does not provide a default role-mapping resolver because the mapping is by definition application-specific.

## Anti-patterns

### Don't synthesise a generic `oauth2_user` role

Templating policies on a placeholder role grants nothing actionable; it just hides the actual decision (scope check) behind a layer of indirection.

### Don't trust scopes the client requested

`AccessToken::scopes` is the **granted** set after the IdP applied its own policy. A client that requested `admin:write` but only got `read` will surface as `scopes: ['read']` here. Policies must check granted scopes, not requested.

### Don't fall back to default-allow

Per the framework's default-deny policy (F12.7), an `Identity` with empty `roles` AND no recognised attribute path means the policy MUST return `false`. The scope-aware policy above does that explicitly via the `is_array($scopes)` discriminator and the local-account fallback.

## Cross-references

- ADR-0025 — OAuth2/OIDC + WebAuthn library adapters (the OAuth2 server contracts).
- ADR-0030 — WebAuthn library adoption (companion 2FA path).
- Audit finding F385.7 — TwoFactorStatus derivation from amr/acr.
- Audit finding F385.10 — this document.
- Audit finding F385.11 — `OAuth2AuthorizationServer` naming (formerly `LeagueAuthorizationServer`).
