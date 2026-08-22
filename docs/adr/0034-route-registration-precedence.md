# ADR-0034: Route registration precedence and collision detection

## Status

Accepted

## Context

Routes reach the router from three sources, registered in this order during
`Kernel::boot()`: framework wirings (e.g. asset and diagnostics routes), then the
project's own `routes/web.php` and `routes/api.php`, then enabled extensions.

Until now the router indexed static routes with a plain last-write-wins
assignment, so a later route silently overwrote an earlier one for the same
method and path, while dynamic routes were merged first-registered-wins. The two
behaviours were inconsistent, and the static case was actively harmful: an
extension that registered a bare top-level path (for example `pulsar/booking`'s
`GET /booking`) silently shadowed a project route on the same path. In production
this served the extension's placeholder page instead of the application's own
page, with no warning and no error. The only workaround was to disable the whole
extension.

An application route expresses more specific intent than a generic extension
default, and a silent wrong-page substitution is exactly the class of failure a
mission-critical framework must never ship.

## Decision

1. Route registration is **first-registered-wins** for both static and dynamic
   routes, keyed by `(method, normalized path, host)`. Because framework wirings
   register before the project, which registers before extensions, the effective
   precedence is **framework > project > extension**.

2. When a later route claims an already-registered key with a **different**
   handler, it is recorded as a `RouteCollision` and excluded from the match
   tables rather than overriding the winner. The boot-time `RouteCollisionReporter`
   logs a warning for each collision in production and **fails closed (throws)** in
   debug mode, so a collision cannot ship unnoticed.

3. A later registration of the **same** route (identical handler and name) is a
   benign duplicate, not a collision. This is the normal result of a non-strict
   route cache being replayed and then the extension booting again and
   re-registering identical routes; it is silently ignored.

4. Extensions must not claim bare top-level paths unconditionally. An extension
   that registers routes must make them **opt-in and prefix-configurable** so an
   application that owns a path can disable or relocate the extension's routes.
   `pulsar/booking` now exposes `routes_enabled`, `route_prefix`, and
   `admin_route_prefix`.

This is a change to a core-architecture path and is therefore governed by
[ADR-0001](0001-ci-gates-and-adr-discipline.md); the extension obligation in point 4
extends the extension lifecycle contract in
[ADR-0004](0004-extension-first-architecture.md).

## Consequences

- Static-route collisions flip from last-wins (extension shadows project) to
  first-wins (project shadows extension), matching the dynamic-route behaviour and
  the intended precedence. Any deployment that accidentally relied on an extension
  overriding a bare project path changes behaviour; this is called out in the
  upgrade notes.
- A latent duplicate route (a genuine conflict, not a cache replay) now fails the
  boot in debug mode instead of silently resolving, surfacing configuration bugs
  early.
- `Router::$collisions` exposes the recorded collisions for diagnostics; the
  route list retains both the winner and the shadowed route so `route:list` still
  shows the conflict.
- Extensions gain a small obligation: ship routes behind an enable flag and a
  configurable prefix. The framework does not enforce this mechanically yet; it is
  a documented convention pending the wiring-contract gate.
