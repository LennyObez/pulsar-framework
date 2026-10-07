# ADR-0056: An unattributable denial is counted, not chained

## Status

Accepted. Deletes one `#[Internal]` class (`AnonymousDenialLedger`), adds one
optional constructor parameter to `ModelBindingMiddleware` and one to
`BindingResolver`, adds one `OptionalBinding` to `ModelBindingWiring`, and adds
two counters to the metrics registry. No `#[Api]` surface changes.

## Context

Route model binding refuses an anonymous request on every bound route under a
regulated preset, and refuses it before any resolver runs — that ordering is what
closed the unauthenticated existence oracle. The audit call moved in front of the
resolver with the check, and the consequence was that one request with no
credentials became one HMAC chain advance and one `LOCK_EX` append into the
tamper-evident chain.

Three successive repairs each traded one defect for another. The last of them,
`AnonymousDenialLedger`, is the one this decision removes.

### A ceiling that can be filled is a suppression switch

The ledger took one chain entry per `(route, reason)` shape and refused
everything past 64 distinct shapes. Past the capacity `claim()` returned `false`
for **every** shape, including shapes it had never seen. A caller who could reach
64 route shapes therefore silenced every anonymous denial that followed — and the
mechanism could not tell an attacker's shape from a legitimate one, because a
capacity does not know what it is refusing.

The capacity was not the mistake; having one was. Any ceiling on an evidentiary
write is reachable by whoever can generate the keys, and the thing on the far
side of it is silence.

### It also bounded nothing under the default deployment

The ledger was per process. Under the persistent worker runtime (ADR-0010) that
is a real bound. Under PHP-FPM, still the default, each request is its own
process and gets its own ledger.

Measured, 300 anonymous requests to one bound route, `BindingPreset::Banking`:

| Runtime                     | chain records | chain bytes | per request |
| --------------------------- | ------------- | ----------- | ----------- |
| One persistent worker       | 1             | 479         | 1.6 B       |
| A fresh process per request | 300           | 143 700     | 479 B       |

The ceiling that was supposed to make the write acceptable was inert in the
deployment most installations run.

### The reason usually given for chaining it is not this framework's reason

It is tempting to say an anonymous denial cannot be chained because there is no
actor. That is false here, and stating it would have been the next round's
defeat: `AuditActor::anonymous()` is a first-class named constructor and
`AuditActorKind::Anonymous` is a declared kind, and `RequestSignatureMiddleware`
already uses both. This framework's own position is that an unattributable
request has a KIND of actor, not none. `AuditLogger::log()` raises
`AuditActorMissingException` only when the actor argument is empty AND no
`RequestContext` supplies one.

## Decision

**A denial decided with no authenticated caller is counted. It writes nothing to
the audit chain and nothing to the filesystem.**

**A denial that names an actor is chained in full, one entry per occurrence, with
no ceiling and nothing in front of it.**

The split is drawn at attribution rather than at severity, and that is what lets
each half be bounded on its own terms.

Three arguments carry it, and each one is checkable:

1. **Nothing was accessed.** The anonymous refusal is decided before
   `ModelBinder::bindWithMeta()`, so no resolver runs — measured at 0 resolver
   calls over 11 000 anonymous requests. The entry would be evidence of an access
   that did not occur; it is a record of traffic, and the access log counts
   traffic.
2. **The key space belongs to the caller.** Without a ceiling, an entry per
   request lets an unauthenticated caller decide how far the chain grows, at 479
   bytes and 916 µs against a 26 µs refusal — the record costing 35× the request
   it records — plus a process-wide serialization point on the sink's lock,
   reachable by anyone who can open a socket.
3. **Every record would be the same record.** Same actor, same action, same
   reason, same models, differing only in a path the caller chose.

The identified half needs none of this: its volume is bounded by the credentials
that exist, and whoever floods it is named in every line they add. It therefore
takes no ceiling, and a failing sink does not cause a denial to be skipped — every
identified denial is attempted and every failure is reported at `critical`.

### The count goes to the metrics registry, labelled from the route table

`pulsar_model_binding_anonymous_denials_total{route,reason}`, resolved once and
held, registered at composition so the series exists at zero from boot.

The labels are the route's name-or-pattern and the reason, and never anything the
request carries. A `Counter` keys a per-label map in memory, so a label the caller
chooses is a map the caller sizes — the same unbounded key space, relocated from
the chain into the process. The route table is fixed at boot; the URL space is
not.

With `observability.metrics.enabled` off there is no registry and the count is
lost. The refusal is unchanged and the `debug` line still carries every
occurrence, which is an acceptable posture and an unacceptable surprise, so
`ModelBindingWiring::describeWiring()` declares `MetricRegistry` as an
`OptionalBinding` and the wiring-contract inspector reports it as a degraded
feature.

### The same rule applies one layer out

`AuthorizationMiddleware` refuses the anonymous caller first on any route
registered through `RouteAccessRegistrar::authenticated()`, because
`Kernel::dispatchRoute()` wraps the post-routing pipeline inside the route-level
one. It wrote the same shape of entry with the REQUESTED PATH as the resource and
no ceiling at all, so leaving it alone would have made this decision cosmetic on
exactly the routes a regulated preset exists for. It counts on
`pulsar_auth_anonymous_denials_total{route,reason}` — the same labels, the same
argument.

### A route that cannot be served is diagnosed once

The same defect appears once more, in the log rather than the chain. A `500` out
of `ModelBinder::plan()` — `ambiguousBoundType`, `undeclaredParent`,
`undeterminedRelation`, `unreadableParent`, `placeholderSharesSegment`,
`containedWithoutParent`, `inconsistentScope` — is read off declarations,
memoised by `BindingResolver` and rethrown unchanged. It is the same message
forever. The middleware logged it at `error` with a stack trace on every request,
on a route an unauthenticated caller can reach: outright under a permissive
preset, and under a regulated one on a `#[PublicRoute]` that declares
`_without_authorization`.

So the diagnosis is written by the component that DECIDES it, at `error`, the one
time it is decided. `handleBindingException()` counts the request on
`pulsar_model_binding_refusals_total{route,status}` and drops its own line to
`debug` without the exception.

This is an aggregation and not a ceiling, and the distinction is structural
rather than rhetorical: there is no capacity, no claim and no shape map. The
deduplication IS the resolver's memo, which must already hold an entry for the
route shape before a replay can happen at all. A key space wide enough to defeat
it would exhaust memory in that memo — which holds an exception per key — long
before it cost a log line, and no arrangement of it can make a new refusal go
unreported.

## Consequences

- An assessor asking "show me what this route refused" gets every denial that
  names somebody, in full, with no sampling and no ceiling. Anonymous refusals
  are answered from the metric and the access log.
- An unauthenticated caller can no longer grow the chain, serialize workers on
  the sink's lock, or bury an identified denial in noise — at any request rate,
  under either runtime.
- An operator loses the per-request `error` line for a broken route and gains one
  line naming it plus a counter. A deployment that wants the per-request line back
  enables `debug`.
- `MetricRegistry` becomes a reported degraded feature of route model binding when
  metrics are disabled.
- `BindingResolver` takes a logger. Constructed without one, a route
  misdeclaration is silent there; the middleware still counts every request the
  route refuses.

## Measurements

PHP 8.5.9, xdebug off. Both shapes of the code loaded from one checkout, the
pre-change classes served to the `before` runs by a prepended autoloader, so the
two are compared under one set of conditions.

**300 anonymous requests to one bound route, `BindingPreset::Banking`:**

| Shape  | Runtime           | chain records | chain bytes | anonymous denials counted |
| ------ | ----------------- | ------------- | ----------- | ------------------------- |
| before | persistent worker | 1             | 479         | —                         |
| before | fresh process     | 300           | 143 700     | —                         |
| after  | persistent worker | 0             | 0           | 300                       |
| after  | fresh process     | 0             | 0           | 300                       |

Zero resolver calls on every one of those runs, both shapes, both when the row
exists and when it does not.

**An anonymous request for an existing row versus an absent one** — ten
independent runs, 5 000 interleaved A/B pairs each (100 000 measured requests),
500-pair warm-up per run:

| Run | existing p50 | absent p50 |    Δ |
| --- | -----------: | ---------: | ---: |
| 1   |         41.7 |       41.7 |  0.0 |
| 2   |         21.3 |       21.7 | +0.4 |
| 3   |         29.2 |       29.1 | −0.1 |
| 4   |         28.5 |       28.4 | −0.1 |
| 5   |         37.7 |       37.7 |  0.0 |
| 6   |         19.9 |       20.1 | +0.2 |
| 7   |         31.9 |       31.8 | −0.1 |
| 8   |         31.6 |       31.5 | −0.1 |
| 9   |         39.0 |       39.0 |  0.0 |
| 10  |         41.2 |       41.4 | +0.2 |

Microseconds. Largest |Δ| is 0.4 µs on a 21 µs request; the sign changes four
times; the mean of the ten deltas is +0.04 µs. The property is structural rather
than lucky — the anonymous path calls no resolver, so whether the row exists is
not an input to it — and it held identically on the pre-change code, so nothing
here regressed it.

**2 000 anonymous requests to a route the plan permanently refuses**, permissive
preset, at production log levels (`debug` dropped before rendering):

| Shape  | lines written | bytes     | per request | p50            | p90         | p99           |
| ------ | ------------- | --------- | ----------- | -------------- | ----------- | ------------- |
| before | 2 000         | 4 540 000 | 2 270 B     | 743.4-756.4 µs | 902.7-955.5 | 1811.0-1991.6 |
| after  | 1             | 2 065     | 1.03 B      | 19.8-41.3 µs   | 59.4-65.3   | 88.4-102.3    |

2 199× fewer bytes; p50 falls by 18-38× depending on machine load, over two runs
of the same benchmark. With `debug` enabled the after shape
writes 2 001 lines and 726 065 bytes — the per-request line survives at 362 bytes
because it no longer carries the exception, against 2 270 bytes before.

## Migration / rollback plan

No application change is required. A deployment that wants the anonymous denial
counts must have `observability.metrics.enabled` on, which is the shipped default;
without it the wiring-contract inspector reports the degraded feature.

Rollback means reinstating a chain write on the anonymous path, which reinstates
the unbounded write; reinstating it with a ceiling additionally reinstates the
suppression switch. Neither is a supported configuration and there is no flag for
either.

## Links

- [ADR-0010](0010-persistent-worker-runtime-request-sandbox.md) — the runtime
  under which a per-process bound is a bound, and under which it is not
- [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md) — why
  the route-misdeclaration diagnosis is written by the component that decides it
- [docs/route-model-binding.md](../route-model-binding.md#an-identified-caller-is-recorded-in-full-an-anonymous-one-is-counted)
- [docs/observability.md](../observability.md#modelbindingmiddleware)
- `tests/Unit/Routing/Binding/AnonymousDenialAuditTest.php`
- `tests/Unit/Routing/Binding/BindingDenialAuditTest.php`
- `tests/Unit/Routing/Binding/BindingRefusalResponseTest.php`
