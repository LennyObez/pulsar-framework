# ADR-0070: The extension API is shared; the trust that ships with it is not

## Status

Accepted. **Supersedes [ADR-0004](0004-extension-first-architecture.md)**
(extension-first architecture with manifest-driven lifecycle). Keeps ADR-0004's
mechanism intact and retracts one claim it made about that mechanism's default posture.
Deletes one dead grant from the shipped `config/extensions.php` and adds one assertion
to `ExtensionSandboxDriftTest`. Adds nothing to the `#[Api]` surface. Continues
[ADR-0023](0023-extension-trust-tiers.md) and [ADR-0047](0047-a-tier-is-granted-never-claimed.md)
into the document those two were amending without ever saying so.

## Context

ADR-0004 says two things that are no longer true of this tree, and one of them is a
security claim.

**"No privileged access. First-party extensions (`extensions/studio/`,
`extensions/payments/`) use `ExtensionInterface` and lifecycle hooks identically to
third-party code. There are no internal backdoors."** and, in its Consequences,
**"Third-party parity. Extension authors have the same capabilities as the framework
team. No 'blessed' extensions with special access."**

`config/extensions.php` ships a pre-granted trust tier for every bundled extension.
Measured at rc.12: 34 entries for 34 bundled manifests — **21 at `core`** and 13 at
`verified`, three of the latter carrying an additional `CryptoKeyAccess` grant
(`pulsar/analytics`, `pulsar/cms`, `pulsar/payments`).

`core` is not a label. `ExtensionBootstrap::scopeContainer()` returns the **real**
container for a Core-tier extension and never builds a `ScopedContainerProxy`; the
router is bypassed the same way. Master key, audit sink, raw database connections, any
route path in the application: conferred by a row in a config file that ships in the box
with 21 names already in it.

The parity ADR-0004 promised is therefore only half present, and the half that is
present is the half that matters least to a reader worried about supply chain:

- **The API is genuinely shared.** There is no interface, hook or container access that
  a bundled extension can use and a third-party extension at the same tier cannot. No
  backdoor was found; ADR-0047 found and closed the one place where trust could be
  _self_-granted, and that fix cut against first-party code too.
- **The shipped defaults are not shared.** A third-party extension is Community until an
  operator edits `config/extensions.php`. Twenty-one first-party names arrive already
  edited in.

ADR-0023 already knew this. It opens its Context with "The current extension system
(ADR-0004) treats all extensions equally … but it creates unacceptable risk profiles"
and then builds the tier system that stopped treating them equally. ADR-0004 was never
marked as amended, so the series has carried both documents as Accepted, saying
opposite things, since rc.4.

Two smaller errors in the same Decision, found while checking the first:

- **The lifecycle has five phases, not four.** `ExtensionBootstrap` runs Register →
  PreBoot → Boot → PostBoot → **Shutdown**, and its own docblock says so
  (`src/Extensibility/ExtensionBootstrap.php:37`). The fifth used to be a loop in
  `Kernel::shutdown()` that handed the extension the **real** container on the way out —
  "four phases scoped and the one nobody counted did not". It is a scoped phase now, and
  a record that counts four cannot describe the fix that made it five.
- **One grant names nothing.** `'pulsar/social-sso' => ['tier' => 'core']` sat in
  `trusted_extensions` for an extension that no longer exists. `extensions/auth/`
  absorbed it, and its manifest lists that name in `replaces` alongside
  `pulsar/oauth2` and `pulsar/webauthn`. The other two replaced names carry no
  grant; this one was left behind. `ExtensionSandboxDriftTest` could not see it, because it asserts every
  _manifest_ has an entry and never asserts that every _entry_ has a manifest. A file
  ADR-0023 describes as "what an auditor reads to learn what third-party code was
  permitted" was carrying a Core-tier row for nothing at all.

## Decision drivers

1. ADR-0023's guarantee section is written for a compliance reader who will not reach
   page four. ADR-0004 is two pages earlier in the series and tells that reader the
   opposite. The earlier document wins by accident of ordering.
2. "No blessed extensions" is a claim about the threat model. It is the kind of sentence
   that ends up in a vendor questionnaire, and it is false as shipped.
3. A grant is a record. A record that names nothing is the defect this repository keeps
   finding elsewhere, and a guard that only checks one direction is
   [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)'s
   subject exactly.

## Decision

**The extension mechanism is shared with third parties in full. The trust that ships
pre-granted is not, and `config/extensions.php` is where that asymmetry is written
down.**

Everything in ADR-0004's Decision survives except the parity claim, and the phase count
is corrected:

- **`pulsar.json` manifest.** Unchanged. Identity, version, `provides` (services,
  routes, commands), framework compatibility, dependencies, `kind`, and a `trust_tier`
  that is a **request** the host may only lower (ADR-0047).
- **Five-phase lifecycle.** Register → PreBoot → Boot → PostBoot → Shutdown. Each phase
  is a separate pass over all extensions in dependency-resolved order, and every phase
  including Shutdown runs through the extension's own scope.
- **Deterministic ordering.** Unchanged. Topological sort by declared dependencies;
  circular dependencies rejected at validation.
- **State machine enforcement.** Unchanged. `ExtensionLifecycle`: Discovered → Validated
  → Registered → Booted → Failed.
- **Capability declaration.** Unchanged, and worth being precise about what it is: the
  manifest's `provides` block declares what the extension **contributes** (services,
  routes, commands). It declares no _security_ capabilities — ADR-0023 explains why a
  declaration written by the code being constrained is not a control.

And, replacing "no privileged access":

- **Parity of mechanism, asymmetry of default.** A third-party extension granted `core`
  in `config/extensions.php` is indistinguishable at runtime from a bundled one:
  identical interfaces, identical hooks, the same proxy bypass. What the framework ships
  is 21 rows already granting that, and nobody else's name in the file.
- **The blessing is host-owned, auditable, and reversible.** It lives in one file under
  the application's change control, not in framework code. An operator who lowers
  `pulsar/studio` to `verified` gets a `verified` Studio; nothing in the framework
  objects. That is the property that makes this defensible where an internal backdoor
  would not be.
- **The shipped file names bundled extensions and nothing else.**
  `ExtensionSandboxDriftTest` now asserts both directions: every bundled manifest has an
  entry (as before), **and** every entry names a bundled manifest. `pulsar/social-sso`
  is removed.
- **Least privilege is the default for bundled products.** Thirteen bundled extensions
  classified `kind: product` run at `verified` — no `ContainerWrite` override, no
  process exec, and no master key unless a row grants it explicitly with its reason
  written beside it. Of the 34 bundled manifests, 33 _request_ `core`; the host grants
  it to 21.

**What is NOT claimed.** Nothing here is a containment boundary. ADR-0023's guarantee
section is the governing statement and is unchanged by this record: the sandbox stops
accidental over-reach and casual abuse by code that is not trying to escape, and does
not stop a determined attacker who already has code execution in the process. An
extension you install is code you chose to run — at every tier, first-party included.

## Alternatives considered

### Ship no pre-granted tiers and make the operator grant all 34

Rejected. Every bundled extension would boot at Community, and a service-registering
extension at Community fails to boot. The first run of a fresh install would be 34 boot
failures and a documentation page telling the operator to paste back the file the
framework declined to ship. The grant would still exist; it would just be un-reviewed
and hand-typed.

### Drop the tier system and return to ADR-0004's flat model

Rejected, and already rejected once by ADR-0023 with better evidence than could be
assembled here: a flat model gives a compromised community extension the master key, the
audit sink and `/login`. Restoring parity by removing the control is the wrong direction.

### Amend ADR-0004 in place

Rejected. The parity sentence is not a stale number, it is the conclusion ADR-0004 drew
from its own Context, and that Context ("first-party features often use internal APIs
unavailable to third-party code, creating a two-tier ecosystem") is worth preserving as
written — it is why the mechanism _is_ shared. The phase count and the dead grant are
factual errors and are corrected rather than argued with; the parity claim is a decision
that changed, and gets superseded.

## Consequences

### Positive

- **The compliance reader gets one answer.** ADR-0004, ADR-0023, ADR-0047 and this
  record now agree: the API is shared, the default trust is not, and the file that grants
  it is the record.
- **A grant naming nothing fails a test.** The drift guard's blind direction is closed,
  and the entry it was blind to is gone.
- **The fifth lifecycle phase is documented where it is enforced.** A reader of the ADR
  and a reader of `ExtensionBootstrap` now count the same phases.

### Negative

- **"No blessed extensions" was a good line and it is gone.** The framework does bless
  21 names out of the box, and saying so plainly is worse marketing than the sentence it
  replaces. It is also the only version an auditor can check.
- **The asymmetry is real and this record does not remove it.** A third-party extension
  needs an operator's edit to reach what a bundled one reaches on install. That is a
  genuine ecosystem cost, and the mitigation — that the edit is one line in a
  host-owned file — does not make it zero.

### Neutral

- **No runtime behaviour changes.** Removing `pulsar/social-sso` removes a grant for an
  extension that cannot be loaded; the effective tier of everything that does load is
  unchanged.
- **Scaffold command unchanged.** `php bin/pulsar make:extension` still generates the
  manifest and directory structure. A scaffolded extension requests `core` and is
  granted Community until the operator says otherwise, which is the intended shape.

## Security impact

Positive but small, and worth stating precisely so it is not over-read.

- One Core-tier grant is deleted from the shipped host config. It was unreachable —
  extension names come from manifests found on disk, and no manifest carries that name —
  so this closes a **record** defect rather than an exploitable one.
- The drift guard now refuses a `trusted_extensions` entry that names no bundled
  manifest. Before this, a grant could be added to the shipped file and survive review by
  looking like the 33 rows around it.
- No tier changes. No capability grants added or removed. The proxy-bypass property of
  Core tier is unchanged and is documented above rather than softened.

## Performance impact

None. Core tier still bypasses `ScopedContainerProxy` and `ScopedRouterProxy` at zero
overhead; the removed row was never resolved against a loaded extension. The added
assertion runs in `ExtensionSandboxDriftTest`, which reads 34 manifests and one config
file.

## Migration / rollback plan

Nothing to adopt. An application carrying its own `config/extensions.php` is unaffected
— the new assertion reads the framework's shipped file, not the application's, and an
operator's own third-party grants are theirs to make.

To roll back: restore the `pulsar/social-sso` row and drop the reverse assertion. There
is no reason to, but the change is that small.

## Links

- [ADR-0004](0004-extension-first-architecture.md) — the superseded decision; its
  Context is still the argument for sharing the mechanism
- [ADR-0023](0023-extension-trust-tiers.md) — the tier system, and the guarantee this
  record does not exceed
- [ADR-0047](0047-a-tier-is-granted-never-claimed.md) — a tier is granted by the host,
  never claimed by the extension
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) —
  why a one-directional guard is not a guard
- `config/extensions.php` — the record of what each extension was granted
- `tests/Unit/Core/Boot/ExtensionSandboxDriftTest.php` — the guard, now bidirectional
