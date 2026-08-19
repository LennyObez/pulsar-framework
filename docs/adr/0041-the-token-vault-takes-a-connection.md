# ADR-0041: The token vault takes a connection, and a compliance control must name what runs

## Status

Accepted. Breaks a signature marked `#[Api(since: '1.0.0')]` during the RC phase, which
[ADR-0001](0001-architecture-decision-records.md) asks us to justify rather than avoid.
Continues the argument of [ADR-0040](0040-tls-intent-belongs-in-the-dsn.md): a control
that reports itself implemented on the strength of code existing is worth less than no
control at all.

## Context

`DatabaseTokenStore` took a raw `PDO`. `PdoConnection` keeps its own PDO private and
nothing in the framework hands one out, so the class could not be constructed from the
container by any caller. It was, in the literal sense, unusable — and it was used
nowhere.

That would have been a dormant wart. What made it a defect is what surrounded it:

- `SecurityWiring` resolved `TokenStoreInterface` eagerly and fell back to
  `InMemoryTokenStore`. Nothing else in the tree binds that interface, and this wiring
  runs eighth in `WiringList` while `DatabaseWiring` runs seventeenth, so the fallback
  was not a fallback. It was the only branch that could ever execute.
- `PciDssMapping` registers Req 3.4 — "Render PAN Unreadable Anywhere It Is Stored" —
  with `ControlStatus::Implemented`, and its description names `DatabaseTokenStore` as
  the store "for production persistence".

So every deployment held PAN tokens in process memory, lost them on restart, and was
told by its own compliance catalogue that the requirement was met by a class that had
never been instantiated. A tokenization vault that forgets its mappings does not
degrade gracefully: the tokens still resolve to nothing, and the PANs they replaced are
unrecoverable.

## Decision

**1. The store takes `ConnectionInterface`.** This is a breaking change to a signature
carrying `#[Api(since: '1.0.0')]`, and it is the right one. The alternative — an
additive `fromConnection()` named constructor beside the PDO one — would have preserved
a signature that no caller can satisfy, at the cost of carrying two internal modes
through 1.0.0 and beyond. Preserving an unusable signature is not backward
compatibility; it is the appearance of it. Going through the connection also means the
vault inherits the TLS settings ADR-0040 put in the DSN, rather than a PDO assembled
elsewhere under different rules.

**2. `TokenStoreInterface` is bound lazily.** `$container->singleton()` with a closure
defers resolution to first use, by which point `DatabaseWiring` has run and a connection
exists. Eager resolution at wiring time is what made the ordering fatal.

**3. Memory remains the answer when there is no database, and only then.** A deployment
with no connection configured has nothing to persist to. The distinction that matters is
that this is now a reachable branch rather than the only one.

## Consequences

Anyone constructing `DatabaseTokenStore` directly must pass a `ConnectionInterface`.
Inside this repository that is nobody; outside it, the previous signature could not be
satisfied without reaching past the framework's own abstraction, so a consumer that
managed it was already relying on something the framework does not offer.

The PCI-DSS Req 3.4 control is now true of a configured deployment rather than of the
source tree. It is still asserted from the catalogue rather than measured at runtime —
`ComplianceVerificationWiring` verifies transport security this way and does not yet
verify the vault. That gap is real and remains: the same shape as the `sslmode` defect,
one layer up. It should be closed by checking which store resolved, not by trusting the
mapping.

Two tests guard the wiring, and both were confirmed to fail with the fix reverted: one
asserts a `DatabaseTokenStore` once a connection is bound after the wiring runs, the
other that memory is used when no connection exists at all.
