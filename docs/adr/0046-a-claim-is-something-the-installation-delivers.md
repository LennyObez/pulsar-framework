# ADR-0046: A claim is something the installation delivers

## Status

Accepted. Changes the framework's shipped `config/compliance.php`, changes one default
container binding and adds four, adds one `#[Api]` class, and changes the exit semantics
of `deploy:check`. Continues [ADR-0045](0045-a-control-status-is-observed-not-written.md),
which made a control's outcome computable from evidence, by fixing the two places that
still asserted things nobody had checked: the config file that names which standards
apply, and the deploy gate that was documented as fail-closed and was not.

## Context

ADR-0045 replaced 207 hand-written `ControlStatus` literals with probes that compute an
outcome from gathered facts. It worked: run against this repository, the resulting
report failed, naming 42 controls that seven enabled frameworks claimed and the
deployment did not show.

That failure was correct, and it exposed the layer ADR-0045 had not reached. The
literals were gone from the mappings, but `config/compliance.php` still shipped this:

```php
'enabled_frameworks' => [
    ComplianceFramework::PciDss,
    ComplianceFramework::Gdpr,
    ComplianceFramework::Hipaa,
    ComplianceFramework::Soc2,
    ComplianceFramework::Nis2,
    ComplianceFramework::Iso27001,
    ComplianceFramework::Iso42001,
],
```

Enabling a framework **is** the claim that the deployment must satisfy it — that is the
design, stated in `ObservationId`'s own docblock and enforced by the absence of any key
that can scope a whole standard out. So the framework was shipping, as its default, the
assertion that every Pulsar installation is subject to PCI DSS, HIPAA, SOC 2, NIS2, ISO
27001, ISO 42001 and the GDPR at once. That assertion was written by whoever first
typed the list, derived from nothing, checked by nothing — which is ADR-0041's defect
one layer above the code: a claim that holds because a file says so.

Enabling every mapping in the catalogue and running the report gives the measurement the
list was never held to. Of 18 mapped frameworks, the default installation delivered
**four** with no gaps — psd2, eidas, hl7_fhir and dsa, each of which has one or two
probed controls and a long operator checklist. Every other framework failed on at least
one fact, and the facts repeat:

| Missing fact                                                 | Controls | Why                                                |
| ------------------------------------------------------------ | -------- | -------------------------------------------------- |
| `incident_reporter_resolved`                                 | 9        | `InMemoryIncidentReporter` was the default binding |
| `classified_route_coverage`                                  | 11       | no route declares a data classification            |
| `health_checks_executed` + `observability_exporter_resolved` | 9        | no runner wired, no exporter configured            |
| `security_features_intact`                                   | 12       | anti-spam features enabled with the cache off      |
| `backup_primitive_resolved`                                  | 4        | there is no backup contract in the tree at all     |
| `erasure_subsystem_resolved`                                 | 3        | `DataPurgeOrchestrator` bound under its class only |
| `pseudonymization_resolved`                                  | 2        | `PseudonymizationService` wired nowhere            |
| `consent_subsystem_resolved`                                 | 2        | only an in-memory consent manager ships            |
| `token_vault_renders_unreadable`                             | 1        | no migration creates the `token_vault` table       |
| the `ai_*` family                                            | 14       | `pulsar/ai-governance` is off by default           |

Three of those are outright wiring defects — the framework ships the durable
implementation and binds the stub, or binds a class and not the contract it implements.
The rest are either things this release genuinely does not ship, or things only a
deployment can supply.

Separately, `.env.production.example` and `.env.example` told operators that
`composer deploy:check` "fails closed" on `debug-mode` and on an empty master key.
Run on this repository with `APP_DEBUG=true` and `PULSAR_MASTER_KEY=`, the command
printed `FAIL debug-mode`, `FAIL master-key` and five more errors — and exited **0**,
having printed "Use `--strict` to enforce a non-zero exit code". `composer deploy:check`
was not a script at all; composer answered "There are no commands defined in the
'deploy' namespace" and exited 0 as well.

## Decision

**1. The shipped `enabled_frameworks` is what a default installation delivers, and
nothing else.** It is now `[Gdpr]`: four probed controls, all four observed, none
resting on a development stub and none deferred to an operator artefact. The config file
lists, per framework, the specific fact a default installation does not deliver, so an
operator adding one knows before the first run what will fail and why.

This is not "claim less to pass". It is the same rule ADR-0045 applied to a control,
applied to a standard: the claim and the check are the same act. A default that claims
seven standards fails the gate for every user of the framework on day one, and a gate
that fails for everyone is a gate everyone learns to ignore.

**2. Three wiring defects are fixed, because they were defects.**

- `IncidentReporterInterface` defaults to `FileIncidentReporter`, writing beside the
  audit trail, falling back to the in-memory register only where audit logging is off.
  The comment it replaces said "override with FileIncidentReporter via config"; no such
  config key existed.
- `DataPurgeInterface` is bound to the `DataPurgeOrchestrator` that was already being
  constructed. It had been registered under its concrete class name only, so
  constructor injection of the contract — the framework's own rule — got nothing.
- `PseudonymizationServiceInterface`, `PseudonymLookupInterface` and
  `ForgetServiceInterface` are bound inside the master-key block. All three classes have
  shipped since 1.0.0 and none was ever constructed outside a test.

**3. `FilePseudonymLookup` is added**, because the pseudonymisation service could not
honestly be bound over the only lookup that existed. `InMemoryPseudonymLookup`'s own
attribute reads "Test/dev pseudonym lookup implementation": a service standing on it can
mint a pseudonym and can never resolve or erase one after a restart, which defeats both
Art 15 and the Art 17 erasure `ForgetService` exists to perform. The new lookup rewrites
the whole document under an exclusive lock rather than appending, because erasure is the
control it serves and a tombstoned append-only file still contains the subject id it was
asked to remove.

**4. `deploy:check` refuses a deploy on any error-severity result.** `--strict` now means
the one stricter thing left — treat warnings as errors — which is also what it means on
`studio:console:guardian:deploy:check`, so the flag has one meaning instead of three.
A `deploy:check` composer script is added, so the invocation both templates document
exists. The supported way to stop a check from refusing a deploy is its severity in
`config/deploy.php`, where the decision appears in a diff.

## Consequences

`composer qa` passes on this repository with the compliance gate intact, and fails the
moment the repository claims something it does not deliver: adding `PciDss` back to
`enabled_frameworks` exits 1 with `Req3.4 — the vault did not render a value unreadable
when it was asked to`, naming the missing `token_vault` table. That is the gate working.

Every framework removed from the default is one this release cannot fully deliver today,
and each is a named piece of work rather than a decision to stop caring:

- **PCI DSS** needs a migration creating `token_vault`. `DatabaseTokenStore` is the
  default binding and writes to a table nothing creates, so the shipped vault throws on
  first use. This is a live bug and the most valuable single item on the list — it is
  ADR-0041's own control.
- **HIPAA, SOC 2, NIST CSF, DORA** need a backup and restore contract. There is none in
  `src/`.
- **SOC 2, CCPA** need a consent manager and a DSAR store that persist. Both accept-lists
  in `ControlEvidenceGatherer` are empty, which is the honest statement that this release
  ships neither.
- **ISO 27001 A.8.3, NIS2 Art 21(i), HIPAA §164.312(a)(1)** and eight more need routes
  tagged with a data classification. The framework supplies the mechanism
  (`RouteInventory`, the `data_classification` route attribute); tagging is the
  application's. Note that the bundled FHIR extension registers seven routes serving
  health resources with no middleware at all, which is a finding in its own right.
- **ISO 42001** needs `pulsar/ai-governance`, a bundled product extension off by default.
  A deployment operating no AI system has no subject for any of its fourteen controls.

The anti-spam features remain bound-but-inert with the cache off — 12 controls turn on
`security_features_intact` — and no framework claiming them is enabled, so the gate does
not hide it; `pulsar debug:wiring` names it, and so does the report for anyone who
enables one of those frameworks.

Changing `deploy:check`'s exit code is a behaviour change for any pipeline that ran it
without `--strict` and read the result. Such a pipeline was already not gating on
anything: it received 0 whatever the checks said. The change makes it start failing on
real errors, which is what it was written for and what both env templates already told
its operators it did.
