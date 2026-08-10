# Superglobal isolation in persistent runtimes

> PHP superglobals (`$_SERVER`, `$_SESSION`, `$_GET`, `$_POST`,
> `$_COOKIE`, `$_FILES`) couple the request handler to process-wide state
> that survives request boundaries in persistent SAPIs (FrankenPHP
> workers, RoadRunner, Swoole). The framework must be able to handle a
> request without depending on superglobals being in the "right" state
> from a previous request.

## Current state (rc.12)

### Done

- **`ServerRequest::fromGlobals()`** accepts injected `$server`, `$get`,
  `$post`, `$cookies`, `$files` arguments so tests construct a request
  without touching `$_SERVER` etc. Default falls back to the real
  superglobals for production SAPI invocations.
- **`ServerRequest::fromGlobals()` body cap** reads `php://input` with a
  hard limit and wraps the result in a `StringStream` — no streaming
  reference to the global resource survives the request.
- **`SessionInterface`** + **`InMemorySession`** abstract the session
  store. Tests and parallel workers wire `InMemorySession` instead of
  touching `$_SESSION`.
- **`TrustedProxy`** + `ConfigDomainResolver` gate `X-Forwarded-Host`
  against an allowlisted source IP, so a stray superglobal value from a
  previous request cannot poison routing.
- **`HeaderValidator`** rejects CR/LF/NUL in any header written via the
  PSR-7 surface, preventing a leaked header in `$_SERVER` from being
  re-emitted unsanitised.

### Remaining work

- **Direct `$_SERVER` reads outside `fromGlobals()`** — a grep across
  `src/**` will surface them. Each one should either flow through the
  request object's `getServerParams()` or take an injected argument.
- **FrankenPHP / Swoole workers reset between requests** — the runtime
  adapter wraps each handler call in `beginRequest()` / `endRequest()`,
  but the contract is not yet asserted by a test that runs 100 sequential
  requests and verifies state isolation.
- **Session save handler isolation** — when a custom save handler is in
  use (Redis, DB), the handler instance lives across requests; verify
  that no per-request state leaks via static caches in the handler.

## How to add isolation to a new code path

1. **Never reference `$_SERVER` / `$_GET` / `$_POST` / `$_COOKIE` /
   `$_FILES` directly outside `Pulsar\Http\Message\ServerRequest`.** Take
   the request object as a constructor argument and call
   `$request->getServerParams()` / `getQueryParams()` etc.
2. **Never reference `$_SESSION` directly outside
   `Pulsar\Security\Session\Session`.** Take the `SessionInterface` from
   the container and call its methods.
3. **For runtime adapters** (FrankenPHP, Swoole, RoadRunner): wrap each
   request in `RuntimeInterface::beforeRequest()` /
   `afterRequest()` so request-scoped state is built fresh from the
   adapter-provided superglobal snapshot and destroyed after the handler
   returns.

## Static analysis hook

A Semgrep rule could enforce rule (1) under `composer security:lint`;
it is not written yet.

## Related ADRs

- ADR-0009 (boundary enforcement) — `\Internal\` namespaces are private,
  and the runtime adapters should be the only callers of superglobals.
- ADR-0017 (persistent HTTP runtime) — FrankenPHP worker lifecycle.
