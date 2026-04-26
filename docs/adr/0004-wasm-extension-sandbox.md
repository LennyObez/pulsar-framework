# ADR-0004: WebAssembly extension sandbox with capability-based security

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.19 (Extension sandbox: wasmtime with capability-based security)
* **Sprint:** Sprint 0.5 (initial ADR batch); implementation in Sprint 4.1
* **Supersedes:** none

## Context

Pulsar Framework supports first-party extensions (CMS, forum, payments, AI, etc.) and third-party extensions (community-contributed plugins). The third-party extension surface introduces a fundamental trust problem: code authored outside the maintainer's review cannot be trusted to preserve framework invariants (audit-chain integrity, capability scoping, resource budgets, regulatory data classification).

Three deployment models address this:

1. **Trust contributors and load extensions in-process.** This is the default in most frameworks. It collapses on the first malicious extension or supply-chain compromise.
2. **Process isolation (one OS process per extension).** Strong isolation, but IPC overhead is unacceptable on the latency budgets Pulsar targets (P99 < 1 ms).
3. **Sandboxed in-process execution with capability-based access control.** Combines low overhead with hardware-backed isolation and audit-traceable capability grants.

The WebAssembly ecosystem in 2026 makes option 3 tractable. WASI Preview 2 component model offers fine-grained capability declarations; `wasmtime` is the canonical Rust runtime with mature performance; the WebAssembly Component Model standardises the cross-language extension boundary.

The capability-based security model is the second half of the answer. Capabilities are unforgeable tokens granted at install time; the absence of a capability is the absence of authority. The audit question "what can this extension observe or mutate?" has a deterministic, manifest-bound answer.

## Decision

Pulsar Framework runs every third-party extension inside a `wasmtime` instance with WASI Preview 2 component-model capabilities. The host enforces capabilities via the Pulsar capability table; extensions receive exactly the capabilities explicitly granted through their `pulsar.toml` manifest, no others.

Capability granularity is fine-grained. Examples:

* `database:read:table:posts` — read access to a single table.
* `database:write:table:user_preferences` — write access to a single table.
* `http:outbound:example.com:443` — outbound HTTPS to a single host:port.
* `filesystem:read:/data/uploads` — read access to a single directory.
* `event:subscribe:user.created` — subscribe to a single typed event.
* `event:publish:order.placed` — publish a single typed event.

No capability is granted implicitly. The installation flow presents the manifest to the administrator for explicit grant. The grant decision is recorded in the audit chain (per ADR-0008 audit invariant).

Resource limits per extension instance are configured by the administrator: memory ceiling (MB), CPU time per invocation (ms), file-descriptor ceiling (count). Crashed extensions do not observably affect the host (per Sprint 4.1 exit criteria).

The implementation lands in **Sprint 4.1** (`pulsar-kernel::sandbox`). The capability table and the manifest schema land in Phase 1 (kernel) so that Phase 2-3 first-party crates can begin defining the capabilities they would expose to third-party extensions before the runtime is fully wired.

## Consequences

### Positive

* Third-party extensions are tractable in regulated environments. The audit question "what can this extension do?" has a deterministic, manifest-bound answer that satisfies regulator review.
* Hardware-backed isolation (WASM linear memory + WASI capability descriptors) prevents extension code from accessing host memory or syscalls outside its grant.
* Crashed extensions do not destabilise the host process; failure isolation is structural.
* Capability grants are audit-chained — every grant decision is reviewable.
* WASM is language-agnostic at the source level; community extensions can be authored in Rust, AssemblyScript, Go, or any language that compiles to WASI Preview 2 components.
* `wasmtime` is the canonical Rust WASM runtime with active maintenance from the Bytecode Alliance.

### Negative

* WASM invocation overhead (target < 100 µs per Sprint 4.1 exit criteria); not zero. First-party crates run in-process at zero overhead, so this only affects third-party extensions.
* Capability table maintenance is ongoing work — every new framework subsystem that exposes capabilities to extensions must register them in the table.
* WASI Preview 2 ecosystem is younger than ABI alternatives like dynamic loading. Some third-party libraries may not yet have WASI-component builds.
* `pulsar.toml` manifest schema is a new artefact contributors must learn.
* The capability-grant flow adds an installation step administrators must perform (mitigated by an admin-console UX in `services/admin/`).

### Neutral

* `wasmtime` version pinned in `[workspace.dependencies]` per plan Section 13.4; quarterly review per risk R-016.
* Resource limits are deployment-tunable; no single profile fits every adopter.

## Alternatives considered

* **In-process trust (no sandbox).**
  Rejected: incompatible with regulated-domain security model; one malicious or compromised extension takes down the framework.
* **OS process isolation (one process per extension).**
  Rejected: IPC overhead unacceptable on Pulsar's latency budgets (P99 < 1 ms per Section XII).
* **OS-level sandboxing (seccomp + cgroups + namespaces).**
  Rejected: still uses OS process boundary; same IPC overhead. Plus Linux-only, breaks cross-platform deployment story.
* **Lua or other embedded scripting language.**
  Rejected: language-locked; fewer existing libraries; no formal capability model. Lua's `setfenv` sandboxing is well-known to have bypass paths.
* **eBPF for in-kernel extensions.**
  Rejected: extension scope (CMS plugins, payment-provider adapters, etc.) does not fit the eBPF programming model; eBPF is for kernel-level observability, not application-level extensions.
* **JavaScript via QuickJS or V8 embedded.**
  Rejected: V8 embedded is a heavy dependency with security history; QuickJS is lightweight but lacks the WASM/WASI capability discipline.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.19, Section III Architecture Overview (WASM sandbox + capability table), Sprint 4.1 (WASM sandbox implementation).
* Risk register entries: R-008 (scope creep), R-016 (Rust ecosystem drift — wasmtime version review).
* Related ADRs: ADR-0001 (full rewrite in Rust), ADR-0002 (modular monolith with hexagonal ports), ADR-0003 (microkernel with formal verification).
* External:
  * wasmtime project. wasmtime.dev.
  * WebAssembly Component Model. github.com/WebAssembly/component-model.
  * WASI Preview 2 specification. github.com/WebAssembly/WASI.
  * Miller, M. S. "Robust Composition: Towards a Unified Approach to Access Control and Concurrency Control." PhD thesis, Johns Hopkins, 2006. (foundational capability-based security work)
* Compliance mapping: ISO 27001:2022 control A.8.31 (separation of development, test and production environments — extension sandbox is the technical control); GDPR Art. 32 (security of processing — capability-bound access mitigates extension data-access risk).
