# ADR-0069: A dependency you require is not a dependency you avoided

## Status

Accepted. **Supersedes [ADR-0003](0003-non-psr7-http-abstractions.md)** (non-PSR-7 HTTP
abstractions). Records no code change: every fact below is already true of the shipped
tree, and what changes is the document that describes it. Adds nothing to the `#[Api]`
surface. Written because [ADR-0001](0001-ci-gates-and-adr-discipline.md) makes the
record binding, and a binding record that is false is worse than none — a reviewer can
cite it.

## Context

ADR-0003 decided that Pulsar would not implement PSR-7, and rested that decision on
four claims. Three of them are about the shape of the API and remain defensible. The
fourth is the one it argued hardest and the one the tree contradicts:

> **Zero external dependencies** - no `psr/http-message`, no `psr/http-factory`, no
> `psr/http-server-handler`.

`composer.json` requires all three, at runtime, in the `require` block — alongside
`psr/http-server-middleware`, `psr/http-client`, `psr/cache`, `psr/clock`,
`psr/container`, `psr/event-dispatcher`, `psr/log` and `psr/simple-cache`. Eleven PSR
packages, four of them HTTP.

They are not carried unused. The core **implements** the interfaces:

| Specification     | Implemented by                                                                                                                            |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| PSR-7 message     | `src/Http/Message/ServerRequest.php`, `Response.php`, `Uri.php`, `Stream.php`, `StringStream.php`, `BufferStream.php`, `UploadedFile.php` |
| PSR-17 factories  | all six: `src/Http/Factory/{Request,Response,ServerRequest,Stream,UploadedFile,Uri}Factory.php`                                           |
| PSR-15 middleware | 55 middleware classes under `src/`; `src/Http/Middleware/MiddlewarePipeline.php` is itself a `Psr\Http\Server\RequestHandlerInterface`    |

And the request path is PSR-7 end to end. `Kernel::handle()` takes a
`ServerRequestInterface` and returns a `ResponseInterface`
(`src/Core/Kernel.php:886`); so does every private method it dispatches through.
`src/` carries 281 references to the `Psr\Http\Message` namespace.

Meanwhile `docs/http.md` — the document ADR-0003 pointed readers at — instructs
controllers to type-hint `Pulsar\Http\Message\ServerRequest`, which _is_ the PSR-7
implementation, and explains which PSR-7 methods you fall back to if you type-hint the
interface instead. The page opens by saying these "are not PSR-7 implementations".

Two of ADR-0003's Consequences follow the same claim into falsehood:

- "**No PSR-15 middleware interop.** Third-party PSR-15 middleware cannot be dropped in
  without an adapter. Pulsar must provide its own middleware contracts." The pipeline is
  a PSR-15 pipeline. Third-party PSR-15 middleware drops in.
- "**Zero dependency surface.** No PSR packages to track, audit, or version-constrain."
  There are eleven, and `roave/security-advisories` tracks them like anything else.

What did survive is real and worth keeping, which is why this is a supersession and not
a reversal:

- `Pulsar\Http\Request` and `Pulsar\Http\Response` still exist as `readonly` value
  objects with backed-enum `Method` and `ResponseStatus`. They are reached through
  `src/Runtime/Bridge/PsrBridge.php`, which converts in both directions, and through
  `extensions/psr7-bridge/`.
- `Method` and `ResponseStatus` are used throughout, PSR-7 or not; the enums were never
  contingent on refusing the interface.
- `StreamedResponse` remains a dedicated path rather than a `StreamInterface` ceremony
  on every ordinary response.

So the decision that actually shipped is not "no PSR-7". It is "PSR-7 in the plumbing,
Pulsar types where they are better", and nobody wrote that down.

## Decision drivers

1. A record cited under ADR-0001 must describe the tree, or it teaches a reviewer to
   approve the opposite of what the framework does.
2. The interop cost ADR-0003 accepted as a Negative — "no PSR-7 library ecosystem",
   "bridge adapters required" — is a cost the framework no longer pays, and a
   consequence nobody pays should not be listed as one.
3. A regulated-domain buyer reads the dependency claim as a supply-chain statement.
   "Zero PSR packages" against a lockfile holding eleven is the kind of discrepancy an
   auditor finds first.

## Decision

**Pulsar's HTTP core implements PSR-7, PSR-15 and PSR-17. The `readonly` value objects
are a second, narrower surface reached through a bridge, not the framework's HTTP
type.**

Concretely, and as the tree already stands:

- **The kernel speaks PSR-7.** `KernelInterface::handle()` and `terminate()` take
  `ServerRequestInterface`/`ResponseInterface`. A runtime adapter that produces PSR-7 —
  RoadRunner, FrankenPHP, the built-in persistent runtime — hands its message straight
  to the kernel with no conversion.
- **The middleware contract is PSR-15.** `MiddlewarePipeline` implements
  `Psr\Http\Server\RequestHandlerInterface` and composes
  `Psr\Http\Server\MiddlewareInterface`. There is a `Pulsar\Http\Middleware\MiddlewareInterface`,
  and it is an empty interface extending the PSR-15 one — a marker for the framework's own
  55 middleware, adding no method. Third-party PSR-15 middleware needs no adapter and no
  Pulsar interface at all.
- **Message construction goes through PSR-17.** All six factory interfaces have a
  first-party implementation under `src/Http/Factory/`, so a library that asks for a
  factory gets one without pulling a second PSR-7 implementation into the tree.
- **`Pulsar\Http\Request`/`Response` remain supported, and remain a bridge away.**
  `PsrBridge` converts PSR-7 → Pulsar and back; `extensions/psr7-bridge/` carries the
  adapters an application needs at its own edges. They are the right type for code that
  wants `readonly` immutability enforced by the language rather than by convention.
- **Controllers type-hint the concrete `Pulsar\Http\Message\ServerRequest`.** It is a
  `ServerRequestInterface` and adds `json()`, `query()`, `post()`, `input()`, `all()`,
  `wantsJson()`. Type-hinting the interface works and loses the helpers; both are
  supported and `docs/http.md` says which you get.

**The PSR packages are declared as runtime requirements and stay there.** They are
interface-only packages, versioned, auditable, and already in `composer.lock`.
Pretending otherwise was the defect.

## Alternatives considered

### Keep ADR-0003 and remove PSR-7 from the core

This is what honouring the old record would mean: unpick `ServerRequestInterface` from
`Kernel`, from 55 middleware, from six factories and from 281 references, and re-issue
the interop cost the framework has already stopped paying. It buys the removal of four
interface-only Composer packages. Rejected: the price is the entire HTTP layer and the
prize is a smaller `require` block.

### Mark ADR-0003 Deprecated and write nothing

Rejected by the series' own rule. "Deprecated" means the decision no longer applies and
nothing replaced it; something did replace it, and a reader arriving at ADR-0003 needs
to be sent somewhere.

### Amend ADR-0003 in place

Rejected. The change is to the decision, not to a fact inside it — ADR-0003's argument
was _for_ an outcome the framework no longer has. Editing it would erase the record of
why the non-PSR-7 position looked right in the first place, which is the one thing
ADR-0003 is now good for. Factual errors get corrected in place; reversed decisions get
superseded.

## Consequences

### Positive

- **The interop cost is gone.** PSR-15 middleware, PSR-18 clients and PSR-17-consuming
  libraries work against the core directly. ADR-0003 listed the absence of this as a
  Negative it accepted; the framework stopped paying it and the record can stop
  claiming it.
- **One PSR-7 implementation in the tree.** Because the core implements PSR-17, a
  library asking for a factory does not drag `nyholm/psr7` or `guzzlehttp/psr7` into a
  production install. `nyholm/psr7` stays in `require-dev`, where it is a differential
  reference rather than a runtime dependency.
- **The supply-chain statement is now true.** Eleven interface-only PSR packages,
  visible in the lockfile, is a claim that survives an audit. "Zero" was not.

### Negative

- **PSR-7's ergonomics are now the core's ergonomics.** `with*()` cloning,
  `StreamInterface` bodies and stringly-typed methods and statuses are present in the
  API that middleware authors write against. ADR-0003 was right that this is worse than
  a `readonly` class with backed enums; the concrete `ServerRequest` and the `Method` /
  `ResponseStatus` enums narrow the gap but do not close it.
- **Immutability is enforced by convention in the PSR-7 path.** `Pulsar\Http\Request` is
  `readonly` and cannot be mutated; `Pulsar\Http\Message\ServerRequest` is a mutable
  class following the PSR-7 `with*()` contract by discipline. Code that wants the
  language to enforce it must use the value object and the bridge.
- **Two request types, and a reader has to know which.** This is the real cost of the
  arrangement, and it is not a cost ADR-0003 anticipated because ADR-0003 did not think
  there would be two.

### Neutral

- **`extensions/psr7-bridge/` keeps its job, with a smaller one.** It converts between
  the PSR-7 core and the `Pulsar\Http\Request`/`Response` value objects. It is no longer
  what makes the framework interoperable — the core is — but it is still what an
  application uses when it wants the value objects at a boundary.
- **`Request::all()` precedence is unchanged.** JSON body > POST > query, documented in
  `docs/http.md`, enforced by tests, and a stable API contract exactly as ADR-0003 left
  it.

## Security impact

None from this record; it changes no code. Worth stating for the reader who arrives from
a supply-chain question: the four HTTP PSR packages are interface-only, carry no
executable logic beyond interface declarations, and are covered by
`roave/security-advisories` like every other requirement. The PSR-7 implementations they
are satisfied by are first-party code under `src/Http/`, subject to the same review,
static analysis and mutation scope as the rest of the framework.

## Performance impact

None from this record. For the reader comparing the two request types: the PSR-7 path is
the one every benchmark in `tests/Benchmark` already measures, because it is the path
the kernel takes. `RequestResponseBench` asserts `Pulsar\Http\Request` and
`Pulsar\Http\Response` construction under 5 µs each; the PSR-7 messages are covered
through `KernelBench` and `EndToEndBench`.

## Migration / rollback plan

Nothing to adopt. Applications already written against either type keep working.

Rolling back would mean reversing the code, not this document — see "Alternatives
considered".

## Links

- [ADR-0003](0003-non-psr7-http-abstractions.md) — the superseded decision, kept for why
  non-PSR-7 looked right
- [ADR-0070](0070-the-extension-api-is-shared-the-trust-that-ships-with-it-is-not.md) —
  extension-first architecture, under which `extensions/psr7-bridge/` ships
- [ADR-0001](0001-ci-gates-and-adr-discipline.md) — CI gates and ADR discipline
- `docs/http.md` — which request type to hint, and what each gives you
