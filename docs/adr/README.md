# Architecture Decision Records

Every numbered decision in this directory records what was decided, why, and what
it cost. This page is the map: which are in force, which were replaced, and by
what. The count is deliberately not written here — it is the first line of the
generated block below, and a second hand-typed copy of it was already wrong by
one the day a record was added.

**This index is generated.** `composer adr:index` rebuilds it from the files
themselves and `composer adr:index -- --check` fails when it has drifted, so the
table below cannot quietly stop matching the series it describes. A
hand-maintained index would decay exactly as the 550-cell compliance grid this
repository removed did — the reason is written down in
[ADR-0045](0045-a-control-status-is-observed-not-written.md).

## How to read an ADR

Every record carries four sections: **Context** (what forced a decision),
**Decision** (what was chosen), and **Consequences** (what it cost, including the
costs the author would rather not have). A record that lists only benefits has not
finished thinking.

The status vocabulary, from [`0000-template.md`](0000-template.md):

| Status | Meaning |
| --- | --- |
| **Proposed** | Written, not yet decided. |
| **Accepted** | In force. The code is expected to match it, and a reviewer may cite it. |
| **Deprecated** | The decision was made and never took effect, or no longer applies, and nothing replaced it. |
| **Superseded by ADR-NNNN** | A later record replaces it. Read the successor for what is in force; read this one for why the earlier answer looked right. |

**Nothing is deleted.** A superseded record is not a mistake to be tidied away —
it is the reasoning that led somewhere, and removing it means the same question
gets re-litigated in a year without the evidence that settled it the first time.
Several records here exist specifically to say that an earlier decision was wrong.
That is the intended use.

## Why the series is binding

[ADR-0001](0001-ci-gates-and-adr-discipline.md) makes the discipline enforceable:
a change to a core architecture path requires a new or updated record, and the
`ADR Governance Check` is a **required status check** on `main`. It is not
advisory, and it is not a convention someone can forget.

One principle recurs across the series more than any other, and it explains
decisions that look severe out of context:

> A check that has never been observed to fail is indistinguishable from no check.

That is [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md).
It is why several gates carry a negative test that plants a defect and watches the
gate refuse it, why suppression baselines may only shrink, and why a compliance
control reports what a deployment was *observed* doing rather than what its
configuration asks for.

## Proposing a change

Open an [architecture proposal](../../.github/ISSUE_TEMPLATE/architecture-proposal.yml)
first. Discussion happens on the issue; the record is written once the shape is
agreed. This saves writing code against a design that will not be accepted. See
[`GOVERNANCE.md`](../../.github/GOVERNANCE.md).

<!-- BEGIN GENERATED INDEX -->

**80 records: 74 in force, 6 superseded or deprecated.**

### In force

| # | Decision | Status |
| --- | --- | --- |
| 0001 | [CI Gates and ADR Discipline](0001-ci-gates-and-adr-discipline.md) | Accepted |
| 0002 | [Modular Monolith with Vertical Slices and Ports/Adapters](0002-modular-monolith-vertical-slices-ports-adapters.md) | Accepted |
| 0006 | [Libsodium-Only Cryptography with Master Key Derivation](0006-libsodium-only-crypto-master-key-derivation.md) | Accepted |
| 0007 | [In-House Observability Stack with No Vendor Dependencies](0007-in-house-observability-stack.md) | Accepted |
| 0008 | [HMAC-Chained Tamper-Evident Audit Logging](0008-hmac-chained-tamper-evident-audit-logging.md) | Accepted |
| 0009 | [Attribute-Based Public API Surface (#[Api] / #[Internal])](0009-attribute-based-public-api-surface.md) | Accepted |
| 0010 | [Persistent Worker Runtime with Request Sandbox Isolation](0010-persistent-worker-runtime-request-sandbox.md) | Accepted |
| 0011 | [Typed Readonly Configuration DTOs with Deterministic Load Order](0011-typed-readonly-configuration-dtos.md) | Accepted |
| 0013 | [Boundary Enforcement via API Interfaces](0013-boundary-enforcement-api-interfaces.md) | Accepted |
| 0014 | [Kernel Service-Wiring Decomposition](0014-kernel-service-wiring-decomposition.md) | Accepted |
| 0015 | [Identity-Scoped Two-Factor Authentication](0015-identity-scoped-two-factor-authentication.md) | Accepted, except for the replay-guard key: partially superseded by |
| 0016 | [Container Dependency Injection](0016-container-dependency-injection.md) | Accepted |
| 0017 | [Introspection Layer](0017-introspection-layer.md) | Accepted |
| 0018 | [Application Cache Layer](0018-application-cache-layer.md) | Accepted |
| 0019 | [Studio Module Extension Surface](0019-studio-module-api.md) | Accepted |
| 0020 | [Event Dispatch System](0020-event-dispatch-system.md) | Accepted |
| 0021 | [Internationalization Subsystem](0021-internationalization-subsystem.md) | Accepted |
| 0022 | [Interactive REPL Shell](0022-interactive-repl-shell.md) | Accepted |
| 0023 | [Extension Trust Tiers & Capability Enforcement](0023-extension-trust-tiers.md) | Accepted |
| 0024 | [Templating Engine and Design System](0024-templating-engine-and-design-system.md) | Accepted, and amended by |
| 0026 | [Database Enhancements](0026-database-enhancements.md) | Accepted, with the connection-pooling configuration retracted |
| 0027 | [Workflow & Saga Orchestration](0027-workflow-saga-orchestration.md) | Accepted |
| 0028 | [Codegen Engine & Control Packs](0028-codegen-and-control-packs.md) | Accepted |
| 0029 | [Service Discovery Architecture](0029-service-discovery-post-ga.md) | Accepted |
| 0031 | [Pull Request size limits and release-PR scope policy](0031-pull-request-size-limits.md) | Accepted (effective immediately, blocks 1.0.0 GA tagging if violated) |
| 0032 | [Homegrown OAuth2/OIDC/WebAuthn/JOSE with mandatory conformance vector gate](0032-homegrown-auth-with-conformance-vectors-gate.md) | Accepted. Supersedes ADR-0025 (OAuth2/OIDC/WebAuthn library adapters) and |
| 0033 | [Bind the loaded Environment as a process-global so `env()` resolves `.env`](0033-active-environment-for-env-helper.md) | Accepted |
| 0034 | [Route registration precedence and collision detection](0034-route-registration-precedence.md) | Accepted |
| 0035 | [CSRF defense-in-depth posture and default token manager](0035-csrf-defense-in-depth-posture.md) | Accepted |
| 0036 | [Unknown configuration key detection](0036-unknown-config-key-detection.md) | Accepted |
| 0037 | [Add SQL Server as a fourth `Driver`, and keep MariaDB a variant](0037-sql-server-as-a-fourth-driver.md) | Proposed |
| 0038 | [A TOTP code is redeemable once, not once per purpose](0038-totp-replay-key-drops-purpose.md) | Accepted. Partially supersedes [ADR-0015](0015-identity-scoped-two-factor-authentication.md) |
| 0039 | [Schema questions belong to the dialect, not to the caller](0039-schema-questions-belong-to-the-dialect.md) | Accepted. Extends [ADR-0037](0037-sql-server-as-a-fourth-driver.md), which recorded that |
| 0040 | [TLS intent belongs in the DSN, and a discarded option is an error](0040-tls-intent-belongs-in-the-dsn.md) | Accepted. Applies the rule of [ADR-0039](0039-schema-questions-belong-to-the-dialect.md) — |
| 0041 | [The token vault takes a connection, and a compliance control must name what runs](0041-the-token-vault-takes-a-connection.md) | Accepted. Breaks a signature marked `#[Api(since: '1.0.0')]` during the RC phase, which |
| 0042 | [Coverage and mutation are bounded by memory, and the gates say so](0042-coverage-and-mutation-are-bounded-by-memory.md) | Accepted. Records a permanent reduction in what two quality gates verify, which |
| 0043 | [Schema belongs to migrations, not to the classes that read it](0043-schema-belongs-to-migrations.md) | Accepted. Retires boot-time DDL framework-wide, which is a change of core-architecture |
| 0044 | [Handler arguments are resolved, not spread](0044-handler-arguments-are-resolved-not-spread.md) | Accepted. Changes how the kernel invokes every controller in every application, and what a |
| 0045 | [A control status is observed, not written](0045-a-control-status-is-observed-not-written.md) | Accepted. Deletes and reshapes types carrying `#[Api(since: '1.0.0')]` during the RC |
| 0046 | [A claim is something the installation delivers](0046-a-claim-is-something-the-installation-delivers.md) | Accepted. Changes the framework's shipped `config/compliance.php`, changes one default |
| 0047 | [A tier is granted, never claimed](0047-a-tier-is-granted-never-claimed.md) | Accepted. Closes a total sandbox escape in `ScopedContainerProxy`, fixes one effective-tier |
| 0048 | [A guard is something a request runs into](0048-a-guard-is-something-a-request-runs-into.md) | Accepted, with decision 1 superseded by |
| 0049 | [A route states who may reach it](0049-a-route-states-who-may-reach-it.md) | Accepted. Gives every route the framework registers an explicit access declaration, |
| 0050 | [A fact is produced only by the component that measures, and only a measurement proves behaviour](0050-a-fact-is-produced-only-by-the-component-that-measures.md) | Accepted. Narrows types carrying `#[Api(since: '1.0.0-rc.12')]` during the RC phase, |
| 0051 | [The dispatched route is handed down, not read off the request](0051-the-dispatched-route-is-handed-down-not-read-off-the-request.md) | Accepted. Adds one `#[Api]` interface |
| 0052 | [An authorization decision does not run the application](0052-an-authorization-decision-does-not-run-the-application.md) | Accepted, and amended by |
| 0053 | [An attestation names the dispatch it was minted for](0053-an-attestation-names-the-dispatch-it-was-minted-for.md) | Accepted. Continues |
| 0054 | [The caller is published by authentication, not written onto the request](0054-the-caller-is-published-by-authentication-not-written-onto-the-request.md) | Accepted. Adds one `#[Internal]` class (`Pulsar\Auth\AuthenticationState`), two methods |
| 0055 | [A type hint cannot switch off authorization](0055-a-type-hint-cannot-switch-off-authorization.md) | Accepted. Adds one factory to `Pulsar\Routing\Binding\ModelBindingException` |
| 0056 | [An unattributable denial is counted, not chained](0056-an-unattributable-denial-is-counted-not-chained.md) | Accepted. Deletes one `#[Internal]` class (`AnonymousDenialLedger`), adds one |
| 0057 | [A guard that is not call-scoped guards nothing](0057-a-guard-that-is-not-call-scoped-guards-nothing.md) | Accepted. Amends [ADR-0052](0052-an-authorization-decision-does-not-run-the-application.md), |
| 0058 | [The AI Act obligation Pulsar can carry is Article 50, and it binds now](0058-the-ai-act-obligation-that-binds-is-article-50.md) | Accepted. Adds one `ComplianceFramework` case (`AiAct`), one requirements object, |
| 0059 | [A chain cannot see its own missing tail](0059-a-chain-cannot-see-its-own-missing-tail.md) | Accepted. Adds four `#[Api]` types (`EvidenceChainHead`, `EvidenceChainHeadAware`, |
| 0060 | [A check that has never been observed to fail is indistinguishable from no check](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) | Accepted. Governs every gate that can block a merge or a release, which |
| 0061 | [A loaded extension is not a measurement](0061-a-loaded-extension-is-not-a-measurement.md) | Accepted. Adds one `#[Api]` enum case (`ObservationGrade::Available`), one `#[Api]` value |
| 0062 | [Proof must be about the control's subject](0062-proof-must-be-about-the-control-subject.md) | Accepted. Adds a required parameter to `ControlDeclaration::probed()` and a required |
| 0063 | [A transparency subsystem is exercised, not resolved](0063-a-transparency-subsystem-is-exercised-not-resolved.md) | Accepted. Purely additive: one `#[Api(since: '1.0.0-rc.12')]` interface |
| 0064 | [A session cipher is measured by sealing something](0064-a-session-cipher-is-measured-by-sealing-something.md) | Accepted. Adds one `#[Api(since: '1.0.0-rc.12')]` interface to the Security module |
| 0065 | [An incident register is measured by recording something, and a pseudonym by erasing it](0065-an-incident-register-is-measured-by-recording-something.md) | Accepted. Adds two `#[Api(since: '1.0.0-rc.12')]` observers to the Compliance module |
| 0066 | [Personal data is measured by classifying something](0066-personal-data-is-measured-by-classifying-something.md) | Accepted. Adds one `#[Api(since: '1.0.0-rc.12')]` observer to the Compliance module |
| 0067 | [The version is declared once and derived everywhere](0067-the-version-is-declared-once-and-derived-everywhere.md) | Accepted. Governs every file in this repository that states which release this is, and the |
| 0068 | [A baseline records what was measured, and a seal is concrete on purpose](0068-a-baseline-records-what-was-measured.md) | Accepted. Adds two `#[Internal]` interfaces — `Pulsar\Auth\Internal\Authorization\BufferedDecisionSinkInterface` |
| 0069 | [A dependency you require is not a dependency you avoided](0069-a-dependency-you-require-is-not-a-dependency-you-avoided.md) | Accepted. Supersedes [ADR-0003](0003-non-psr7-http-abstractions.md) (non-PSR-7 HTTP |
| 0070 | [The extension API is shared; the trust that ships with it is not](0070-the-extension-api-is-shared-the-trust-that-ships-with-it-is-not.md) | Accepted. Supersedes [ADR-0004](0004-extension-first-architecture.md) |
| 0071 | [A fiber-keyed map is not concurrency](0071-a-fiber-keyed-map-is-not-concurrency.md) | Accepted. Supersedes [ADR-0005](0005-synchronous-core-controlled-fiber-usage.md) |
| 0072 | [A budget is the assertion that runs, not the JSON beside it](0072-a-budget-is-the-assertion-that-runs.md) | Accepted. Supersedes [ADR-0012](0012-performance-budgets-advisory-ci.md) (performance |
| 0073 | [A directive that emits a field nobody reads is not support](0073-a-directive-that-emits-a-field-nobody-reads-is-not-support.md) | Accepted. Amends [ADR-0024](0024-templating-engine-and-design-system.md) |
| 0074 | [A directive is template syntax only where the template is](0074-a-directive-is-template-syntax-only-where-the-template-is.md) | Accepted. Amends [ADR-0024](0024-templating-engine-and-design-system.md) |
| 0075 | [An HTML form can only send the verb a browser sends](0075-an-html-form-can-only-send-the-verb-a-browser-sends.md) | Accepted. Establishes the routing convention for admin routes an HTML form submits to, |
| 0076 | [A shared cache refuses identity rather than keying on it](0076-a-shared-cache-refuses-identity-rather-than-keying-on-it.md) | Accepted. Governs `src/Http/Cache/HttpCacheMiddleware.php`. Changes behaviour for |
| 0077 | [A ceiling is a ratchet only while its reason names the number, and a by-reference argument is a write](0077-a-ceiling-is-a-ratchet-only-while-its-reason-names-the-number.md) | Accepted. Changes no runtime code and no `#[Api]` surface: one CI analyser, one test-support |
| 0078 | [A stream that cannot say it finished has not finished](0078-a-stream-that-cannot-say-it-finished-has-not-finished.md) | Accepted. Adds one method to a published `#[Api]` interface — a recorded RC-phase break — plus |
| 0079 | [A classifier that did not look is not a clean bill of health](0079-a-classifier-that-did-not-look-is-not-a-clean-bill-of-health.md) | Accepted. Adds one `#[Api(since: '1.0.0-rc.12')]` decorator over |
| 0080 | [An inference nobody recorded is one nobody can defend](0080-an-inference-nobody-recorded-is-one-nobody-can-defend.md) | Accepted. Adds `Pulsar\AI\Audit\` — one `#[Api]` decorator over |

### Superseded or deprecated

Kept deliberately. Read the successor for what is in force; read these
for why the earlier answer looked right at the time.

| # | Decision | Status |
| --- | --- | --- |
| 0003 | [Non-PSR-7 HTTP Abstractions](0003-non-psr7-http-abstractions.md) | Superseded by [ADR-0069](0069-a-dependency-you-require-is-not-a-dependency-you-avoided.md). |
| 0004 | [Extension-First Architecture with Manifest-Driven Lifecycle](0004-extension-first-architecture.md) | Superseded by [ADR-0070](0070-the-extension-api-is-shared-the-trust-that-ships-with-it-is-not.md). |
| 0005 | [Synchronous Core with Controlled Fiber Usage](0005-synchronous-core-controlled-fiber-usage.md) | Superseded by [ADR-0071](0071-a-fiber-keyed-map-is-not-concurrency.md). |
| 0012 | [Performance Budgets with Hybrid CI Enforcement](0012-performance-budgets-advisory-ci.md) | Superseded by [ADR-0072](0072-a-budget-is-the-assertion-that-runs.md). |
| 0025 | [OAuth2/OIDC + WebAuthn Library Adapters](0025-oauth2-oidc-webauthn-library-adapters.md) | Superseded by [ADR-0032](0032-homegrown-auth-with-conformance-vectors-gate.md) |
| 0030 | [WebAuthn extension must use `web-auth/webauthn-lib`, not a homegrown implementation](0030-webauthn-library-adoption-required.md) | Superseded by [ADR-0032](0032-homegrown-auth-with-conformance-vectors-gate.md) |

<!-- END GENERATED INDEX -->

## Reading paths

If you are new, these are the records that explain the most:

**The shape of the system**
[ADR-0002](0002-modular-monolith-vertical-slices-ports-adapters.md) modular
monolith · [ADR-0070](0070-the-extension-api-is-shared-the-trust-that-ships-with-it-is-not.md)
extension-first architecture, and the trust that ships with it ·
[ADR-0016](0016-container-dependency-injection.md) the container

**Why the HTTP layer looks the way it does**
[ADR-0069](0069-a-dependency-you-require-is-not-a-dependency-you-avoided.md) — the
core implements PSR-7, PSR-15 and PSR-17, and the `readonly` value objects are a
second surface reached through a bridge. Its predecessor,
[ADR-0003](0003-non-psr7-http-abstractions.md), argued the opposite and is kept for
why that looked right.

**Security and cryptography**
[ADR-0006](0006-libsodium-only-crypto-master-key-derivation.md) libsodium only ·
[ADR-0008](0008-hmac-chained-tamper-evident-audit-logging.md) tamper-evident audit
· [ADR-0023](0023-extension-trust-tiers.md) extension trust tiers

**Compliance, and why its numbers are small**
[ADR-0045](0045-a-control-status-is-observed-not-written.md) a status is observed,
not written · [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md)
a fact comes only from what measures it ·
[ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)
a check that cannot fail

Those three are the argument behind an audit that found half the controls reported
as satisfied were not, and moved the number down rather than defending it.
