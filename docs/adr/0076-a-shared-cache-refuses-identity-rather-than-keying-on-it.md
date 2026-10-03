# ADR-0076: A shared cache refuses identity rather than keying on it

## Status

Accepted. Governs `src/Http/Cache/HttpCacheMiddleware.php`. Changes behaviour for
`cache.private` and adds the `cache.share_across_clients` request attribute; adds no
`#[Api]` surface.

## Context

`HttpCacheMiddleware` stores a response under a key computed from the request's method,
path and query string. Nothing else. There is no per-user variant, no session identifier,
no `Vary` negotiation — `computeCacheKey()` reads exactly those three things.

That is a correct key for a document that is the same for everyone, and a disclosure for
anything else. Two callers requesting `/account` produce the same key, so the second is
served the first one's page. If the first response carried a `Set-Cookie`, the second
caller is handed someone else's session.

The question this record answers is what to do about it. There are two candidate designs
and the difference between them is not performance.

## Decision

**A shared cache refuses any request or response that carries identity, in both
directions. It does not widen the key to include it.**

### Why not key on identity

The obvious alternative is to mix a session identifier into the key, so each caller gets
their own entry and personalised pages become cacheable. It is rejected.

An identity-keyed shared cache multiplies the blast radius of any future key defect by
the number of users. A cache that only ever holds public documents leaks a public
document when its key is wrong; a cache that holds every user's personalised page leaks
one user's page to another. The failure mode of the first is a stale banner. The failure
mode of the second is a regulated-domain incident, and the incident is invisible — a page
rendered for the wrong caller looks exactly like a page rendered for the right one.

The refusal costs one cache miss on a personalised route. That is the entire price, and
it is paid per request on routes that were never safe to share anyway.

The second reason is that "carries identity" is a property this middleware can _observe_,
while "which identity" is one it would have to be _told_. Observation degrades safely: a
header it has not thought of is not a header it silently keys on.

### The refusal list, exhaustively

**Request side** — the response is produced and returned, never stored, and marked
`X-Cache: BYPASS`:

- the `cache.private` attribute is truthy
- a non-blank `Cookie` header
- a non-blank `Authorization` header
- a non-blank `Proxy-Authorization` header

**Response side** — evaluated after the handler runs, and these win over any request-side
opt-in:

- a non-blank `Set-Cookie` header — the cookie belongs to the one caller it was minted
  for, and storing the response stores the cookie with it
- a non-blank `Vary` header — `Vary` names request dimensions the response depends on,
  and the key holds method, path and query, so it cannot tell two variants apart. Most
  pointedly it cannot tell apart the two that matter, `Vary: Cookie` and
  `Vary: Authorization`
- a non-blank `Transfer-Encoding` header — a stored entry is replayed as a plain buffered
  response, so a stored transfer coding describes a framing the replay does not use. That
  is a desync, not a slow page
- `Cache-Control` containing `no-store`, `no-cache` or `private`, matched anywhere in the
  comma-separated value

### `cache.private` no longer stores at all

It previously stored the entry and marked it private. It now takes the request-side
refusal path: nothing is written, nothing is read, the response is marked
`X-Cache: BYPASS`, and `Cache-Control: private, max-age=N` is added only when the
response carries no `Cache-Control` of its own.

An attribute named `private` on a store with no per-caller key was a contradiction, and
it was resolved in the direction that cannot disclose.

### `cache.share_across_clients` is the escape hatch, and it is bounded

Some routes carry a `Cookie` header and produce a response that genuinely is the same for
everyone — a public page on a site that sets an analytics cookie, for example. The
attribute lets the application assert that.

Its evaluation order is deliberate and is part of the decision: **it waives the
request-side identity headers only.** The response-side refusals run afterwards and win.
An opted-in route that emits a `Set-Cookie` or a `Vary` is still not stored.

**Accepted residual risk.** The response-side checks read headers. They do not inspect the
body. A route that renders the caller's name into the page while declaring nothing about
it is stored and served to the next caller, and the middleware has no way to know. The
assertion is therefore unverifiable, and is only as good as the route it is placed on.
This is a genuine foot-gun and is documented as one in `docs/caching.md`, `docs/http.md`
and `docs/upgrade.md` rather than hidden. Closing it would need either body inspection or
removal of the opt-in; both are larger decisions than this record makes.

### `X-Cache` is the operator-visible signal

`HIT` served from the store, `MISS` produced and stored, `BYPASS` produced and
deliberately not stored. Without it a cold cache and a cache that will never warm look
identical from outside, and an operator debugging "why is this route slow" cannot tell a
warm-up problem from a refusal. Three paths emit no `X-Cache` header at all; they are
listed in `docs/caching.md`.

## Consequences

### Positive

- A caller cannot be served another caller's response through this middleware without a
  route explicitly asserting shareability, and even then not if the response says
  otherwise.
- The refusal lists are extracted from `IDENTITY_REQUEST_HEADERS` and from the body of
  `responseRefusesSharing()` by a test, so the documentation cannot drift from the code.
- An operator can see the decision per request rather than inferring it from latency.

### Negative

- **A deployment relying on the previous behaviour will see cache misses it did not see
  before.** Any route that sets a cookie, varies, or is reached with a session cookie is
  no longer stored. That is the point, and `docs/upgrade.md` says so plainly rather than
  describing it as a tuning change.
- `cache.private` stores nothing, so a deployment using it as a "cache but mark private"
  switch loses the caching half.
- The opt-in exists and can be misapplied. See the residual risk above.

### Neutral

- Nothing here changes what a _browser_ or a downstream CDN does; those obey the
  `Cache-Control` header, which is unchanged except where this middleware adds one to a
  response that had none.

## Links

- [ADR-0069](0069-a-dependency-you-require-is-not-a-dependency-you-avoided.md) — the live
  record of the HTTP abstractions this middleware is written against, superseding
  [ADR-0003](0003-non-psr7-http-abstractions.md)
- [ADR-0048](0048-a-guard-is-something-a-request-runs-into.md) — why the refusal is in
  the request path rather than in a checker something has to call
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)
  — why the refusal lists are extracted by a test rather than retyped in prose
- [`docs/caching.md`](../caching.md) — the operator-facing statement of both lists
- [`docs/http.md`](../http.md) — `NoCacheMiddleware` and the error-path headers
- [`docs/upgrade.md`](../upgrade.md) — what a deployment relying on the old behaviour sees
- `src/Http/Cache/HttpCacheMiddleware.php` — the implementation the lists are read from
