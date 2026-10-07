# ADR-0075: An HTML form can only send the verb a browser sends

## Status

Accepted. Establishes the routing convention for admin routes an HTML form submits to,
which became necessary once
[ADR-0073](0073-a-directive-that-emits-a-field-nobody-reads-is-not-support.md) removed
`@method` and left no server-side reader for a spoofed verb. Adds no `#[Api]` surface;
changes route registrations in the `cms`, `forum` and `payments` extensions and adds one
test.

## Context

An HTML form can send `GET` or `POST`. That is the whole list. There is no third option,
and after ADR-0073 there is no verb-tunnelling path into this framework either — the
absence is a property `docs/security/asvs-l2-matrix.md` claims under ASVS V14.5, and it
is why `Kernel::dispatchRoute()` answers `501` for anything the `Method` enum does not
name.

Meanwhile 26 admin form sites across three extensions submitted to routes registered for
`PUT` or `DELETE` only. Every one of them answered `405`. They had presumably worked
while `@method` compiled a `_method` field, except that nothing ever read that field
either — so they had never worked; the failure had merely been invisible in a panel
nobody had a test for.

The naive repair is to widen every such route to accept `POST`. Applied to a delete
route on the resource path, that is a defect and not a repair. `POST /admin/cms/media/7`
already means "apply this representation to the resource" — it is the save form's
target. Widening the delete route to `POST` on the same path makes two forms with
opposite meanings resolve to whichever handler was registered second. A save that
deletes is a worse outcome than a `405`.

## Decision

Three rules, applied to every admin route an HTML form submits to.

**1. An update route is registered `[POST, PUT]` under a single name.** `POST` is what
the browser sends. `PUT` is retained because an API client with a real HTTP stack can
send it and should not be told the resource is not updatable. One name, one handler, one
permission — the verb is a transport detail, not a second endpoint.

**2. A delete route never shares the resource path's `POST` slot.** It gets a
`{path}/delete` sub-path registered for `POST`, named `<delete route name>.post`,
running the same handler with the same permission. `DELETE {path}` stays registered and
unchanged for API clients.

The sub-path exists because the collision above has no safe resolution on a shared path,
and a separate path is the cheapest thing that cannot collide. Naming it by suffixing
the existing route's name, rather than inventing a name, keeps the two halves findable
from each other and makes the pairing mechanical enough to check.

**3. The permission is the delete route's permission, not the update route's.** Two ways
to reach one handler must not be two authorization answers
([ADR-0049](0049-a-route-states-who-may-reach-it.md)).

### The invariant is a test

`tests/Unit/Http/AdminFormRouteMethodTest.php` walks the registered routes and the form
targets in the shipped admin views and asserts every form reaches a route that accepts
the verb the browser will send. It was observed failing on 26 sites before the repair
and passes after.

Its docblock states the two classes of defect it deliberately does **not** cover, so the
gap is recorded rather than implied:

- a form whose target has no route at all under any method — a `404`, not a `405`, and a
  different repair (an endpoint has to be written)
- a read-only path, where a form target that only ever `GET`s is out of scope

## Consequences

### Positive

- 7 update routes widened and 13 delete sub-paths added across `cms`, `forum` and
  `payments`; the 26 failing form sites reach their handlers.
- The convention outlives the change. Without it the next admin form is registered
  `PUT`-only again, and a maintainer widening a `DELETE` route to `POST` on the resource
  path reintroduces the save/delete collision — which is the one outcome here that
  silently destroys data rather than refusing.
- No verb spoofing is reintroduced. Nothing reads a `_method` field; the browser's verb
  is the verb.

### Negative

- Two registrations per deletable resource instead of one, and a URL (`{path}/delete`)
  that is not what a REST purist would write. That is the cost of HTML forms having two
  verbs, and it is paid in the routing table rather than in a body parameter the server
  has to trust.
- The `.post` naming convention is enforced by the test rather than by the router, so a
  route registered outside the shipped extensions is not held to it.

### Neutral

- 46 admin form targets have no route under any method at all. They are the `404` class
  the test excludes, they need endpoints written rather than methods widened, and they
  are recorded as outstanding.

## Links

- [ADR-0073](0073-a-directive-that-emits-a-field-nobody-reads-is-not-support.md) — why
  there is no `_method` reader, and why widening the route is the replacement
- [ADR-0049](0049-a-route-states-who-may-reach-it.md) — why the second registration
  carries the same permission
- [ADR-0034](0034-route-registration-precedence.md) — the precedence rules that decide
  which handler a colliding registration reaches
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)
  — why the convention is a test and not a paragraph
- [`docs/security/asvs-l2-matrix.md`](../security/asvs-l2-matrix.md) — the ASVS V14.5 row
  this convention keeps true
- `tests/Unit/Http/AdminFormRouteMethodTest.php` — the guard, and the record of what it
  does not cover
