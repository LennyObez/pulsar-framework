# ADR-0047: A tier is granted, never claimed

## Status

Accepted. Closes a total sandbox escape in `ScopedContainerProxy`, fixes one effective-tier
resolution defect in `ExtensionBootstrap`, deletes one `#[Api]` interface and one internal
class that no code path could reach, adds one `#[Api]` exception factory, corrects the
shipped `config/marketplace.php`, and rewrites what five documents and six docblocks say
about extension trust. Continues [ADR-0041](0041-the-token-vault-takes-a-connection.md)'s
finding into [ADR-0023](0023-extension-trust-tiers.md)'s subject. Adds no feature: extension
signature verification remains something Pulsar does not have, and this ADR is partly a
record of saying so out loud.

## Context

ADR-0023 was careful. It wrote, in the decision itself: "Extensions declare a `trust_tier`
in `pulsar.json` — this is **metadata only**, not a security boundary." The manifest DTO
named the field `requestedTrustTier` rather than `trustTier`. `ExtensionSandbox`'s docblock
put it in one line: "A manifest cannot self-elevate to Core; trust is granted by the host,
never claimed by the extension."

The code did not do that. `ExtensionBootstrap::resolveEffectiveTier()` read:

```php
$requested = $manifest->requestedTrustTier;

if ($this->trustedExtensionsConfig !== null) {
    return $this->trustedExtensionsConfig->effectiveTier($extensionName, $requested);
}

return $requested;
```

The last line is the whole defect. With the capability policy engaged and no host allow-list
attached, an extension received the tier its own `pulsar.json` asked for. A manifest carrying
`"trust_tier": "core"` was granted Core — and Core is the one tier that bypasses
`ScopedContainerProxy` and `ScopedRouterProxy` entirely. Raw container, raw router, master
key, audit sink, any path in the application: conferred by a JSON file sitting in the same
directory as the code it describes, verified by nothing.

Three things kept this from being exploitable in a default install, and none of them is the
control:

1. `ExtensionSandbox::harden()` — added later, by a different fix — always sets both
   properties together, so the shipped boot path was safe.
2. `capabilityPolicy` is a public property. Any embedder setting it directly (as five tests
   in this repository do) got the unsafe path, silently.
3. The unsafe path is indistinguishable from the safe one at the call site. Nothing failed.

That is ADR-0041's shape exactly: a control whose guarantee held because one caller happened
to remember a second assignment, documented as though it held structurally.

The audit that produced this ADR also asked what verifies a manifest at all. The honest
answer, which had not been written down anywhere a reader would find it:

- **Nothing does.** There is no signature verification for extensions anywhere in
  `src/Extensibility/`, no publisher key, no trust store, and no verifier on the load path.
- `src/Integrity/` looks like the mechanism and is not. `ManifestSigner` signs with a subkey
  of the **host's own** master key (`subKeyId=6`, context `integ_sg`). That makes a deployed
  tree tamper-evident to its operator, which is worth having; it cannot attest that a third
  party published an extension, because no third-party identity is involved anywhere in it.
  It is also `enabled => false` by default.
- `config/marketplace.php` shipped `'verify_signatures' => true` under the comment "Verify
  extension signatures before installation". `MarketplaceConfig::fromArray()` — already
  fixed by an earlier pass in this family — throws `ConfigException` on any value but
  `false`, with the message "extension signatures are verified nowhere in the framework, so
  enabling this would record a check that never runs". So the framework shipped a config
  file asserting a control that does not exist, which was simultaneously the one config file
  its own DTO refused to load. Nothing caught it because the marketplace module is
  classified `INERT` in `ConfigRepositoryContractTest` and never loaded.

And one control in the same directory was built, unit-tested, documented in a table headed
"Framework-enforced boundaries" — and constructed by nothing. `ScopedEnvironmentProxy`
filtered `getenv()` by trust tier against a list of sensitive variable prefixes. Grep for
its name outside its own test returns two lines of `docs/extensions.md`.

### The escape that made all of it moot

`docs/security/asvs-l2-matrix.md` had already recorded, against V10.2, that "the trust-tier
sandbox is not creditable". It was right, and the reason is three lines of data:

```php
safeServices: [
    // ...
    'Pulsar\Container\ContainerInterface',
    'Pulsar\Routing\RouterInterface',
],
```

`Kernel` binds both to the real objects — `$this->container->instance(ContainerInterface::class, $this->container)`
and the same for the router. `ScopedContainerProxy::get()` returns a safe-classified service
straight from the inner container. So any proxied extension, at any tier including
`Untrusted`, reached the master key in two calls:

```php
$container->get(ContainerInterface::class)->get(MasterKey::class);
```

and registered a route at `/admin` in two more, prefix and all. Every capability in the
policy, every entry in the restriction map, and the tier fix described below were decoration
on top of that. The framework's own sibling sandbox for CMS plugins already refused
`ContainerInterface` — `CmsPluginIsolationTest::deniesContainerInterfaceResolution` has
asserted it all along — so this was not a design position anyone had taken twice. It was one
list, missing one rule.

## Decision

**0. The sandbox may not hand out the objects it mediates.** `ScopedContainerProxy` now
refuses `Pulsar\Container\ContainerInterface`, `AdvancedContainerInterface`, `Container`,
`Pulsar\Routing\RouterInterface` and `Router` for every tier it proxies, ahead of the
restriction map and unconditionally, with a dedicated
`CapabilityDeniedException::forSandboxEscape()` whose message says there is no grant that
unlocks it — because there is not, and the deny-by-default message it would otherwise have
inherited invites the reader to "add the service to the safe allowlist", which is exactly how
the hole was made. The two entries also come out of `ServiceRestrictionMap::defaults()`, with
a comment in their place.

The check lives in the proxy rather than in the map on purpose. A restriction map is data; a
data file that can switch the sandbox off is not a boundary, and this one did. Core tier is
unaffected because it is never proxied at all. No bundled extension resolves either object
from the container, so nothing in the tree changes behaviour.

`ManifestTierIsNotACredentialTest::aSandboxedExtensionCannotResolveTheContainerAndWalkAroundTheProxy`
runs the escape end-to-end through `ExtensionBootstrap::register()`; four unit tests in
`ScopedContainerProxyTest` cover all five ids, prove no `additional_capabilities` grant
unlocks them, and prove a hand-written map that re-classifies the container as safe still
gets refused. All eight fail against the previous implementation, five of them with "no
exception thrown".

**1. An absent allow-list is an empty allow-list.** `resolveEffectiveTier()` now runs the
requested tier through a `TrustedExtensionsConfig` unconditionally, falling back to an empty
one. Unlisted means Community, whether the host supplied a list with no entry for this
extension or supplied no list at all. The manifest's tier survives as the lower bound of
`min(requested, granted)` and can only ever de-privilege.

That asymmetry is the entire justification for parsing an unverified field, and it is now
pinned from both sides in `ManifestTierIsNotACredentialTest`: a manifest claiming `core`
with no allow-list attached is denied `CryptoKeyAccess` and has its routes prefixed under
`/ext/acme/evil/`, while a manifest claiming `untrusted` is held to Untrusted even though
the host granted it Core. The first two tests fail against the previous implementation.

Backward compatibility is unchanged in the direction that matters: a null `capabilityPolicy`
still means the sandbox is off entirely, which is ADR-0023's documented rollback and remains
the only way to get unmediated access.

**2. `ScopedEnvironmentProxy` and `EnvironmentInterface` are deleted, not wired.** Wiring was
considered and rejected on the merits. Unlike the container — a genuine chokepoint, because
an extension has no other way to obtain a restricted service — environment variables are
reachable through `getenv()`, `$_ENV` and `$_SERVER` from any code in the process. A proxy
over them is a gate that the constrained party opts into and steps around in one call. It
protects against an honest mistake and against nothing else, while occupying a row in a table
that told a regulated operator their extensions could not read `PULSAR_MASTER_KEY`.

Deleting it costs nothing real. `ExtensionCapability::EnvRead` is not orphaned: it already
gates the `Pulsar\Config\Environment` **service** through `ServiceRestrictionMap`, which is
enforcement at the boundary that actually holds. `EnvironmentInterface` was `#[Api]`, but
nothing bound it, so no application could ever have received an instance — the removal has no
possible consumer. `docs/extensions.md` moves `EnvRead` into the "Operationally-enforced
boundaries" table alongside `ProcessExec` and `NetworkEgress`, with the true control: keep
secrets out of the environment of a process that loads untrusted extensions.

**3. `config/marketplace.php` ships `verify_signatures => false`,** with a comment saying why,
and a test loads the real file through `MarketplaceConfig::fromArray()` so the shipped config
can never again claim a control the DTO refuses. `ExtensionListing::isVerified()` and
`isOfficial()` keep their names — they answer questions about a catalogue entry — but their
docblocks now say that "verified" names the tier a registry recorded and is not evidence this
framework verified anything.

**4. What is checked is written where a reader will hit it.** `ExtensionSandbox`'s docblock
enumerates the check exactly (the extension's self-declared _name_ is looked up in the host's
list; a miss is Community; the manifest tier then caps from below) and then states what is
not checked, naming `src/Integrity/` specifically so the next reader does not repeat the
audit's own wrong guess about it. `docs/extension-versioning.md` gains a section titled
"What boot time does not check: manifest authenticity" immediately after the list of boot
steps, because that list reads as exhaustive and is exhaustive — of the well-formedness
checks. `docs/extensions.md` gains "Where an extension's tier comes from" ahead of the
capability tables. `TrustTier`, `ExtensionManifest::$requestedTrustTier` and
`ExtensionBootstrap` say the same thing at the point of use.

## Alternatives considered

**Implement signature verification.** This is the fix that would make `trust_tier` mean
something on its own, and it is a feature, not a defect repair: a signature format, a
publisher identity model, a trust store with rotation and revocation, a signing step in the
extension release process, key distribution for first-party extensions, and a policy for what
an unsigned extension does at boot in each environment. It touches release engineering and
the marketplace as much as it touches `src/Extensibility/`. Landing a half-built version of
it — hashing manifests with the host's own key, say — would produce exactly the artefact this
family of defects is made of: something an auditor reads as provenance that proves only that
the file has not changed since the host first saw it. It belongs in its own change, with its
own ADR, and until then the framework says plainly that it has no such thing.

**Delete `trust_tier` from the manifest.** Tempting — the field grants nothing — but wrong.
It is honoured as a self-restriction, which is a real property: an extension can ship
declaring `untrusted` and be held to it in a deployment that would have granted more. That is
a safe direction by construction. Removing a public readonly property during RC to delete a
working feature is the wrong trade.

**Cap the tier at Community whenever the manifest requests more than Community.** Would close
the same hole, and also break the legitimate case where a host grants Core to a first-party
extension whose manifest correctly declares Core. `min()` against a host list already
expresses the rule; the defect was the branch that skipped it.

## Consequences

An embedder that set `capabilityPolicy` without `trustedExtensionsConfig` will see its
extensions drop to Community and start hitting `CapabilityDeniedException`. That is the fix
working: those extensions were running at whatever tier they had written for themselves. The
remedy is to attach a `TrustedExtensionsConfig` naming the extensions the host trusts, which
is what `ExtensionSandbox::harden()` does from `config/extensions.php` and what every
deployment booting through `Kernel` already gets.

The framework now states, in the four places an operator or auditor is likely to look, that
extension trust in Pulsar rests on the host having typed a name into `config/extensions.php`
— and on nothing else. That is a weaker claim than the tier vocabulary implies on its face,
and it is the true one. `config/extensions.php` is inside the default integrity scope, so
tampering with the allow-list after deployment is detectable once integrity verification is
enabled; extension directories are not, and the doc says so, with the one-line remedy.

One classification is left as observed rather than changed, and named so the next reader does
not have to find it twice. `Pulsar\Extensibility\ExtensionRegistry` is still on the safe list,
and it is mutable: `add()` and `setState()` are public. A Community extension resolving it
cannot obtain any restricted service — the registry holds manifests and lifecycle states, not
services — but it can flip another extension's state and stop it booting, which is a
denial-of-security rather than an escalation. That is a different question from this ADR's
(it is about what a safe service may do, not about who granted the tier), it wants the
read-only `ExtensionCatalogInterface` the registry already implements, and it deserves its own
assessment rather than being folded in here on the way past.

Two weaknesses are named here and left standing, because neither is repairable without the
feature above and both are worse to imply are handled than to state:

- **Trust is keyed on a name the extension supplies.** `ExtensionRegistry::add()` refuses a
  second extension claiming a name already taken, so an impostor can inherit
  `pulsar/orm`'s Core listing only by being discovered first — which requires write access to
  an extension path, the same access that would let it edit the real `pulsar/orm` outright.
  The squat adds nothing to an attacker who already has that access, which is why it is a
  note and not a fix.
- **Nothing ties an extension on disk to a publisher.** Reviewing an extension before listing
  it is, today, the whole of the control. Treat it exactly as adding a Composer dependency.
