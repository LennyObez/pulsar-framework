# Diagram 03 — Request lifecycle (HTTP/JSON example)

A representative HTTP request traverses the layered architecture from the network boundary down to the persistence backend and back. This sequence diagram traces a single authenticated `POST /api/v1/posts` request through every quality-relevant interaction the framework imposes.

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Hyper as hyper (TLS/HTTP3)
    participant HTTP as pulsar-http
    participant Guard as pulsar-guard<br/>middleware pipeline
    participant Auth as pulsar-auth<br/>session + OAuth2
    participant Authz as pulsar-authz
    participant API as pulsar-api<br/>handler
    participant CMS as pulsar-cms<br/>aggregate
    participant ORM as pulsar-orm<br/>repository
    participant Audit as pulsar-audit
    participant DB as PostgreSQL 16+
    participant Bus as Event Bus

    Client->>Hyper: POST /api/v1/posts<br/>Authorization Bearer JWT<br/>Idempotency-Key uuid7
    Hyper->>HTTP: TLS 1.3 + HTTP/3 decoded request
    HTTP->>Guard: middleware before-hook chain
    Guard->>Guard: csrf check (origin + referer)
    Guard->>Guard: ratelimit check (per-IP+per-user bucket)
    Guard->>Guard: ssrf-guard (outbound URL validation; n/a here)
    Guard-->>HTTP: pass

    HTTP->>Auth: extract session token + verify JWT
    Auth->>Auth: TLA+-verified session state machine<br/>Anonymous → Authenticating → Authenticated
    Auth-->>HTTP: AuthContext{user_id, scopes, tenant_id}

    HTTP->>Authz: check_permission("posts.write", user_id, tenant_id)
    Authz->>Authz: RBAC + ABAC + ReBAC composition
    Authz-->>HTTP: ALLOW (with consistency token)

    HTTP->>API: route to typed handler<br/>(Router trie — TLA+ verified)
    API->>API: extract typed body via pulsar-form<br/>(MIME sniff + validation)
    API->>API: idempotency-key check<br/>(replay-cache miss → proceed)

    API->>CMS: create_post(PostDraft)
    CMS->>ORM: repository.insert(Post)
    ORM->>DB: BEGIN TRANSACTION
    ORM->>DB: INSERT INTO posts (...) RETURNING id
    DB-->>ORM: Ok(Post{id})

    ORM->>Audit: append AuditEntry<br/>(seq, ts, actor, action, target, hmac chain)
    Audit->>Audit: HMAC-chain integrity invariant<br/>(Creusot-verified append)
    Audit->>DB: INSERT INTO audit_log

    ORM->>DB: COMMIT
    ORM-->>CMS: Post

    CMS->>Bus: emit PostCreated event<br/>(typed, single-writer)
    Bus-->>CMS: ack
    Bus->>Audit: subscriber: audit-trail completion
    Bus->>API: subscriber: response shaping

    CMS-->>API: Post
    API-->>HTTP: typed Response 201 Created<br/>+ Location header
    HTTP->>Hyper: serialise + add observability headers
    Hyper-->>Client: HTTP/3 frames + 201

    Note over Audit,DB: Audit-chain HMAC link to prev entry<br/>verifiable at any time without full re-read
    Note over Bus: Event ordering total within partition<br/>(per-aggregate-id hash)
```

## Narrative

The lifecycle exercises every non-trivial subsystem in the framework on a single authenticated write request. Six properties of the trace are framework invariants:

1. **Constant-time path resolution.** The router trie (`pulsar-kernel::router`) resolves `POST /api/v1/posts` in O(path length), not O(routes). TLA+ spec `spec/router.tla` proves "each path resolves to at most one route" as a safety invariant.
2. **TLA+-verified session state transitions.** The session state machine (`pulsar-kernel::session`) admits only the transitions encoded in the type-state pattern; the verification rejects any path that bypasses Authenticating between Anonymous and Authenticated. Spec `spec/session.tla`.
3. **Defence-in-depth at the security-controls layer.** `pulsar-guard` (Section 4.11) chains six checks (CSRF, SRI, incident, ratelimit, resilience, SSRF). For this request, CSRF + ratelimit fire; SSRF would fire on outbound calls (not present here); SRI is server-side template-only. Each sub-module is independently testable and Creusot-contracted.
4. **Compose rather than ladder.** RBAC + ABAC + ReBAC compose in a single `pulsar-authz` evaluation. The composition is associative, identity-respecting, and absorption-respecting (per Sprint 2.6 property tests).
5. **HMAC-chained audit append is verification-complete.** `pulsar-audit::AuditChain::append` is Creusot-contracted and produces an entry whose HMAC links to the previous entry's HMAC. Tampering with any single byte fails verification — proven by TLA+ spec `spec/audit.tla`.
6. **Event bus is typed and single-writer per request.** `Bus::emit::<PostCreated>` admits only the typed event. Subscribers register at startup; the dispatch is total-order within a partition (per-aggregate-id hash). Subscribers cannot observe partial state because the emit happens after the ORM commit.

**Latency budget.** Per plan Decision 2.27 + Section XII, this end-to-end path must complete:

* Median (P50): < 100 µs (hello-world equivalent — no DB write)
* P99 at 1 000 req/s: < 1 ms (writes included)
* P99.99 tail: < 5 ms

The middleware pipeline overhead is < 100 ns at five-middleware depth (Sprint 1.5 exit criterion). The router resolution is < 1 µs at 10 000 routes (Sprint 1.4 exit criterion). The audit-chain append is < 5 µs in-memory + ~10 ms PostgreSQL persistence (Sprint 1.2 + 2.4 exit criteria). PostgreSQL transaction time is the dominant tail-latency contributor.

## Cross-references

* ADR-0003 (formally verified microkernel — router + session + audit + middleware + DI)
* Plan Section III Architecture Overview (request lifecycle prose)
* Plan Section XII success metrics (latency budgets)
* Plan Section 14.18 per-subsystem performance budgets
