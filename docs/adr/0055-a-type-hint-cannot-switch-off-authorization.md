# ADR-0055: A type hint cannot switch off authorization

## Status

Accepted. Adds one factory to `Pulsar\Routing\Binding\ModelBindingException`
(`unbindableBoundType()`, HTTP 500) and one public static method to
`Pulsar\Core\Controller\HandlerParameter` (`builtinCanHoldObject()`).
`BindingResolver::bindableClasses()` becomes `classifyType()` and answers a second
question. Additive to the public API surface; no signature changes.

## Context

Route model binding decides what a route parameter binds by reflecting the handler's
type hint. `BindingResolver` asked `ReflectionNamedType::isBuiltin()` and read `true` as
"this parameter is not a model".

That reading is right for `string`, `int`, `float`, `bool` and `array`: `/notes/{note}`
with `show(string $note)` wants the raw segment and gets it, and
`UnauthenticatedDisclosureTest` pins that behaviour.

It is wrong for four names. `isBuiltin()` also returns `true` for `object`, `mixed`,
`iterable` and `callable` — measured, all four — and every one of them can hold an
object. A parameter declared with one of them therefore produced no binding, so
`ModelBinder::plan()` returned an empty plan, so `ModelBindingMiddleware` handed the
request straight on. Handing it on skips everything the binding layer decides: the
regulated preset's identity requirement, and the `AuthorizationHookInterface` call.

### What that was worth from outside

Measured before the change, anonymous caller, `BindingPreset::Banking`, the same route
and the same handler class:

| hint                   | status  |
| ---------------------- | ------- |
| `object $user`         | **200** |
| `mixed $user`          | **200** |
| `object\|string $user` | **200** |
| `iterable $user`       | **200** |
| `BenchModel $user`     | 401     |

A handler author changing a type hint — a change no security review reads as one —
turned mandatory authorization off for that route.

### The framework already answered this question correctly, elsewhere

`HandlerParameter::nameAccepts()` decides whether a declaration accepts a resolved
model on the delivery side, and it names exactly those four builtins as object-bearing,
with a comment explaining why `instanceof` cannot see them. The binder reconstructed the
same question from `isBuiltin()` and got the opposite answer. Two answers to one
question is the defect; the fix has to remove one of them, not correct it.

## Decision

**A handler parameter that admits an object without naming a bindable class is a route
the resolver cannot serve, and the route is refused.**

`ModelBindingException::unbindableBoundType()` carries 500 and is raised from
`BindingResolver::decideForRoute()` — after explicit bindings are applied, exactly like
`ambiguousBoundType()`, so that the advice the message gives ("name the model with
`Router::model()`") can actually be taken. `resolveForRoute()` memoises the refusal per
route shape, so it is computed once per process and replayed.

**Refusing the ROUTE, not the request, is what keeps it from being an oracle.** The
answer is read off the handler signature and the route path, both route-table data, so
it is the same answer for every caller and every id. An anonymous caller under a
regulated preset never reaches it at all: `decideAuthorization()` runs on the
plan-refusal path before the diagnosis becomes a response, so they get the ordinary 401.

**The set is exactly `HandlerParameter::builtinCanHoldObject()`.** That predicate is now
the single definition — a private constant on `HandlerParameter` listing `mixed`,
`object`, `iterable`, `callable` — and both `nameAccepts()` and `classifyType()` read
it. A name added to it in a future PHP is object-bearing on both sides at once.

Three families are deliberately untouched:

- **Scalars** (`string`, `int`, `float`, `bool`, `array`, `false`, `true`, `null`) keep
  the documented pass-through. No object satisfies them, so no model was ever declared.
- **An untyped parameter** keeps it too. There is no declaration to read, and
  `HandlerParameter::accepts()` answers `false` for one on the delivery side — the two
  sides agree, which is the property this ADR exists to restore.
- **Interfaces** keep it. `BindingResolver` has always documented `Countable` as "not a
  model", and narrowing that would change what every interface-typed route parameter in
  every application does. It is a separate decision, not a consequence of this one.

## Consequences

Measured after the change, 2,000 requests per case, anonymous caller,
`BindingPreset::Banking`, Xdebug off:

| hint                  | status     | handler   | resolver calls | p50     |
| --------------------- | ---------- | --------- | -------------- | ------- |
| `BenchWidget` control | 401 × 2000 | 0/2000    | 0              | 27.9 µs |
| `object`              | 401 × 2000 | 0/2000    | 0              | 27.6 µs |
| `mixed`               | 401 × 2000 | 0/2000    | 0              | 27.5 µs |
| `object\|string`      | 401 × 2000 | 0/2000    | 0              | 27.6 µs |
| `iterable`            | 401 × 2000 | 0/2000    | 0              | 27.7 µs |
| `?object`             | 401 × 2000 | 0/2000    | 0              | 27.6 µs |
| `callable`            | 401 × 2000 | 0/2000    | 0              | 27.5 µs |
| `string` control      | 200 × 2000 | 2000/2000 | 0              | 15.8 µs |

Status, reason phrase, headers and body hash to **one distinct value** across the
control and every refused hint, and the p50 spread is 0.4 µs — so a misdeclared route
and a well-formed one are the same 401 to an unidentified caller, in shape and in cost.

The refusal is answerable: `Router::model('widget', Widget::class)` with the same
`object` hint gives 200 × 2000, 2,000 resolver calls, and the hook asked about the
caller — the binding and the authorization the hint used to skip.

**Log amplification does not follow.** A 500-coded `plan()` refusal is diagnosed once
per route shape by `BindingResolver` and re-served from the memo, so 2,000 anonymous
requests to one `object`-hinted route on a permissive preset produce **1** `error` line
and 2,000 `debug` lines — the same level ordinary 4xx traffic already uses. The
ordering constraint this ADR was expected to create against the error-log amplification
work does not apply, because that work landed first.

Applications carrying such a hint today move from a silent 200 (or, for `object`,
`iterable`, `callable` and `?object`, a `TypeError` at the controller call) to a 500
that names the parameter, the handler and the two ways to fix it. The message reaches
the log; the response carries a status and a reason phrase and nothing else.
