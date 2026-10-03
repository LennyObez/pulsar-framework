# ADR-0067: The version is declared once and derived everywhere

## Status

Accepted. Governs every file in this repository that states which release this is, and the
`version` field of every bundled extension manifest.

Applies [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)
to the version: the gate this ADR introduces has a negative test, and the reason it needed
one is that its predecessor checked a single file and was green while everything around it
drifted.

## Context

Pulsar declared its version in one place and repeated it in a hundred and one others.
`composer.json` carried `"version": "1.0.0-rc.12"`, and `tools/version/sync-version.php`
derived exactly one file from it — the four compile-time constants in
`src/Core/Version.php`. That check ran in `composer qa` and in `ci.yml`, it was green, and
it had been green throughout the following:

- **26 of the 34 bundled extension manifests** still declared `1.0.0-rc.11`.
- **Seven declared `1.0.0`** — a release the framework has not made — while their
  `pulsar.min_version` in the same file bound them to a release candidate.
- **One was still at `0.2.0`**, four minor series behind everything it ships with.
- **`.github/SECURITY.md`** told anyone deciding how to report a vulnerability that
  `1.0.0-rc.11` was the version receiving security fixes, and that anything below it was
  not supported. Both statements named a release that was no longer current.
- The README status line, the installation guide, the issue template's version dropdown,
  the RC benchmark workflow's prompt, the component catalogue's "the current release is…",
  and three lines of the roadmap were each a release behind.
- `extensions/mcp-server`'s `MessageHandler` advertised `serverInfo.version` to every MCP
  client that completed a handshake as `'1.0.0-rc.9'`, a private constant three releases
  stale. Its test asserted `assertArrayHasKey('version', …)`, which any string satisfies.

None of these was a bug anybody introduced. Each was a correct string that stopped being
correct while nothing was comparing it to anything, which is the same finding ADR-0060
recorded about the gates and ADR-0045 recorded about compliance statuses, arriving a third
time in a third vocabulary.

The obstacle to fixing it mechanically is that **most mentions of an old version are
right.** `CHANGELOG.md` is a list of them. Every ADR narrates the release it was written
for. `#[Api(since: '1.0.0-rc.11')]` records when an API appeared and must never move.
`@deprecated Since 1.0.0-rc.11` is equally permanent. A repository-wide replacement of the
old string would corrupt every one of those, and a repository-wide ban on the old string
would have no green state short of deleting the project's history. Any enforcement has to
separate a claim about the present from a record of the past, and no derivation can do that
from the text alone.

## Decision drivers

1. A statement that tells a reporter which version receives security fixes is read at the
   moment it matters most and cannot be a release behind.
2. Thirty-four manifests plus a dozen documents cannot be kept in step by anybody
   remembering to, and the evidence is that nobody did.
3. The separation between a claim and a record has to be recorded by a person, because
   nothing else can make it — but a record written by a person must itself be falsifiable,
   or it becomes the next thing that rots.

## Decision

**`composer.json`'s `version` field is the single source of truth.** Every other declared
version in the repository is derived from it or verified against it by
`tools/version/sync-version.php`, wired as `composer version:check` in `composer qa` and in
`ci.yml`, with `composer version:sync` writing the derived values back.

### The field stays; the tag is verified against it

`composer validate` warns that a published package should omit `version` and let the git tag
be the truth. The warning names a real hazard — a field disagreeing with the tag misleads
every reader — but the remedy does not fit this repository:

- **There is no tag to derive from.** `git describe` reports "No tags can describe HEAD";
  the only tags in the history are `backup/*` and `pre-rebase-*`. Releases are cut from
  `composer.json`.
- **The tree must be self-consistent before the tag exists.** Everything the gate governs
  has to be correct in the commit that _prepares_ a release — a commit no release tag points
  at. Under tag-as-truth, the enforced state of a release branch is "still says the previous
  version", which is the drift itself, promoted to policy.
- **Dropping the field degrades the runtime answer.** `Version::full()` prefers
  `Composer\InstalledVersions::getPrettyVersion('pulsar/framework')`, which in an installed
  application is the tag Composer resolved — the honest answer, and it stays. In a source
  checkout it is the root package, and the root package reports `1.0.0-rc.12` today _only_
  because the field is present. Without it, Composer reports `dev-<branch>` and the framework
  begins telling applications it is a branch name.

The hazard is therefore closed from the other end: **a version-shaped tag pointing at HEAD
must equal `composer.json`'s version.** The one moment the two can disagree — the release
commit — is the one moment the gate compares them.

### Bundled extensions version in lockstep, and now provably

`docs/extension-versioning.md` already stated the rule: core extensions are versioned in
lockstep with the framework, community extensions independently. Three conventions coexisted
in the tree not because the rule was ambiguous but because nothing enforced it. The rule is
ratified as written and enforced:

- a bundled `pulsar.json` declares the framework's version as its own;
- its `pulsar.min_version` is that same version — the only value correct under both readings
  of `PulsarVersionConfig::isSatisfiedByCurrent()`, which compares against `Version::short()`
  today;
- a `requires` / `suggests` entry naming a `pulsar/*` sibling declares `>=` that version,
  because bundled extensions ship together and a lower floor advertises a pairing that never
  existed;
- an entry naming a package this repository does not ship is refused, and so is a
  composer-style `require` key, which `ExtensionManifest` does not read: a dependency claim
  nothing enforces must not sit in a manifest looking as though it is enforced.

Community extensions are untouched. The gate never sees them.

### Prose is governed by a declared map, and the map is falsifiable

Sentences that claim which version this is are recorded in `VERSION_GATE_DECLARED_SITES` —
file, pattern, and the form the sentence spells the version in. That is a hand-written list,
which this repository distrusts on principle, so it is falsifiable in both directions:

- **A site whose pattern matches nothing fails the build.** Rewording the sentence does not
  quietly retire the claim; it forces somebody to look at the map again. Without this, the
  map decays into exactly the shape ADR-0060 is about — a check that passes because it has
  stopped looking.
- **Every mention the map does not account for fails the build.** The gate scans the tracked
  and untracked tree for release-candidate tags that are not the current version. A mention
  is accepted only if the line introduces it with `since` or `deprecated` (a record of when
  something appeared or stopped being recommended), if it lives in a tree that is narration
  by construction (`CHANGELOG.md`, `docs/adr/`, any `tests/` directory, the generated API
  snapshot), or if its file is recorded in `VERSION_GATE_NARRATED_FILES` **with a reason a
  reviewer can check**. A new file announcing an old release as current is refused rather
  than joining the drift.

The scan carries a positive control: it first searches for the current version in the file
it was read from, and exits 2 if the search cannot find it. A scan reaching nothing must not
report a clean tree.

Test expectations are deliberately not derived. `tests/Unit/Core/VersionTest.php` and
`tests/E2E/BootPipelineTest.php` pin the version as a literal and fail loudly on a bump,
which makes them checks rather than drift.

## Alternatives considered

### Let the git tag be the source and drop the field

Rejected on the three facts above. It is the right answer for a package whose repository is
only ever consumed through Packagist; it is the wrong answer for a repository that also
documents itself, must be internally consistent before it is tagged, and today has no
release tags at all.

### A single `sed` over the old version string

Rejected because it is wrong on most of its matches. It would rewrite the changelog, every
ADR, every `#[Api(since:)]` marker and every `@deprecated Since` line — turning a
correct historical record into a false one, silently, everywhere at once.

### Extension versions independent of the framework

Rejected. Independent versioning is what community extensions get, and it costs the consumer
a compatibility matrix. Bundled extensions are installed as one unit with one release, so
the matrix has one cell; declaring anything but the framework's version makes a claim about
combinations that have never been built or tested.

### Keep the check narrow and rely on the release checklist

This is the status quo the finding came out of. A checklist was what the seven manifests
claiming `1.0.0` and the security policy naming `rc.11` were already covered by.

## Consequences

### Positive

- A release bump is `composer.json` plus `composer version:sync`, rather than 34 manifests
  and a dozen documents edited by hand.
- The supported-versions table cannot name a version that is not current.
- Two dead dependency declarations were removed on the way in: `pulsar/dora`'s
  `require: {"pulsar/core": "^1.0"}`, naming a package that does not exist under a key the
  manifest parser does not read, and `pulsar/grpc`'s suggestion of `pulsar/runtime`, which is
  a framework module rather than an installable extension.
- The MCP server now computes the version it advertises instead of holding a literal, and its
  test asserts the value rather than the presence of the key.

### Negative

- `VERSION_GATE_DECLARED_SITES` and `VERSION_GATE_NARRATED_FILES` are maintained by hand, and
  a legitimately reworded sentence costs a line in the map. That cost is the mechanism: it is
  what converts a silent decay into a visible decision.
- The gate depends on `git` being available, and reports rather than passing when it is not.
  Every context that runs `composer qa` is a git checkout.

### Neutral

- `composer validate` continues to emit its advisory warning about the `version` field. The
  answer is this ADR, and the hazard the warning names is enforced against by the tag check.
- Two version literals remain in the test suite by design; both fail on a bump, which is the
  point of them.

## Security impact

Direct and the reason this was prioritised. `.github/SECURITY.md` is the document a person
consults before deciding whether to report a vulnerability privately, and it named
`1.0.0-rc.11` as the supported release while the current release was `1.0.0-rc.12` — an
invitation to conclude that the version in hand was out of support and that a private report
was pointless. That table is now derived, and a build in which it disagrees with the
framework version does not pass.

No attack surface, cryptography, authentication or data handling changes.

## Performance impact

None on any runtime path. `Version::full()` is unchanged. The gate is a build-time script;
it runs two `git grep` invocations and reads roughly fifty small files, and it is the second
step of `composer qa` because it costs milliseconds.

## Migration / rollback plan

**Adoption** is complete in the commit that introduces this ADR: `composer version:sync` has
been run and `composer version:check` passes. The release procedure gains one step —
`composer version:sync` after editing `composer.json`, then `pnpm format:fix` if a markdown
table changed width.

**Rollback** is removing `@version:check` from the `qa` script and the corresponding step
from `ci.yml`. Nothing else depends on the gate, and the derived values in the tree stay
valid; they simply stop being defended. `qa:parity` will refuse the two halves being removed
separately, which is the intended coupling.

## Links

- `tools/version/sync-version.php` — the gate, with the argument for the field and the maps
- `tests/Unit/Tooling/VersionConsistencyGateTest.php` — the planted defects and the observed
  refusals
- [`docs/extension-versioning.md`](../extension-versioning.md) — the lockstep rule this ADR
  ratifies and enforces
- [ADR-0001](0001-ci-gates-and-adr-discipline.md) — CI gates and ADR discipline
- [ADR-0045](0045-a-control-status-is-observed-not-written.md) — a status is observed, not
  written
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) — a
  check never observed to fail is indistinguishable from no check
