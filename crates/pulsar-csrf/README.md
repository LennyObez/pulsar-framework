# pulsar-csrf

Cross-Site Request Forgery (CSRF) protection middleware: synchroniser-token + double-submit cookie + `Origin`/`Sec-Fetch-Site` validation.

## What

Implements the OWASP-recommended CSRF defence pattern: a per-session synchroniser token bound to the session via HMAC, paired with a double-submit cookie + `Origin`/`Sec-Fetch-Site` header verification. The middleware rejects any state-changing request (`POST` / `PUT` / `PATCH` / `DELETE`) that fails any of the three checks, and cooperates with `pulsar-http` to attach the per-session token to outgoing rendered HTML forms via `pulsar-engine`'s template directive.

## Why

CSRF is the canonical example of an attack where the user's authenticated session is weaponised against them by a third-party origin. The defence is well-understood (synchroniser tokens since 2008) but easy to get wrong — most frameworks ship CSRF-disabled-by-default, leaving downstream apps to remember to enable it. Pulsar inverts this: CSRF protection is on by default for every state-changing route, and any opt-out requires an explicit `#[csrf_exempt]` attribute that lands in the audit chain.

## How

`pulsar-csrf` is a v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51 (granular CVE blast-radius + independent semver). It depends on `pulsar-kernel` for the constant-time-equality primitive, on `pulsar-http` for the middleware wiring, and on `ring` for the HMAC primitive. The Creusot contract on `verify_token` lives at the Sprint 1.5 sprint exit per Decision 2.20 formal-verification scope.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.5 (`pulsar-guard` historic sprint, now split per Decision 2.51). See [`docs/plan.md`](../../docs/plan.md) Section IV.11.1 for the per-crate spec and Section V.1.5 for the implementing sprint.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
