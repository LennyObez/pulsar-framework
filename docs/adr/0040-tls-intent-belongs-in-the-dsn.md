# ADR-0040: TLS intent belongs in the DSN, and a discarded option is an error

## Status

Accepted. Applies the rule of [ADR-0039](0039-schema-questions-belong-to-the-dialect.md) —
that per-engine questions are answered by the dialect, not by the caller — to the one
connection setting whose engine disagreement was silently costing us a compliance claim.

## Context

`ConnectionConfig::$options` is an `array<string, mixed>` that `PdoConnection` handed to
PDO's fourth constructor argument. PDO indexes driver options by integer attribute
constant. **String keys are discarded without a diagnostic.**

Three things had grown on top of that fact, each reasonable alone:

1. `RuntimeVerifier`, remediating a failed `runtime.db_tls` check, instructed the operator
   to "set `database.options.ssl_mode` to `require` or `verify-full`". Nothing else
   documented the key — `config/database.php` ships `'options' => []` — so the verifier's
   own message was the specification operators followed.
2. `ComplianceVerificationWiring::connectionUsesTls()` read `ssl_mode`/`sslmode` back out
   of the options array and, finding `require` or stronger, reported PCI-DSS
   encryption-in-transit as satisfied.
3. `PdoConnection::fromConfig()` carried the annotation
   `/** @var array<int, mixed> $pdoOptions */` over a value declared `array<string, mixed>`.

Together they formed a closed loop that never touched the database. The framework
instructed a setting, read that setting back, and certified itself compliant on the
strength of it — while the option was dropped on the floor and the connection came up in
plaintext. PHPStan at max and Psalm at level 1 were silent because item 3 told them the
keys were integers. A false `@var` is an `@phpstan-ignore` that does not look like one.

Verified empirically on PHP 8.5.9: a string-keyed option never reaches the driver, and a
`pgsql` DSN without `sslmode` negotiates whatever the server's default allows. Compliance
strict mode booted successfully against it. Three attempts to refute the finding failed.

The defect is not PostgreSQL-specific. It is triggered by the _spelling_: any deployment
writing `ssl_mode`/`sslmode` gets the false pass on either engine. MySQL is sound only
when configured through the integer `Pdo\Mysql::ATTR_SSL_*` attributes, which survive
because they are integers.

## Decision

**1. `sslmode` is a DSN parameter, and `Driver` owns it.** `Driver::buildDsn()` takes an
optional `$sslMode` and appends `;sslmode=…` for PostgreSQL, which is where libpq reads
it. MySQL and SQLite have no such DSN parameter, so passing one to them raises rather
than being ignored — a setting that cannot take effect must not be accepted quietly.
Per ADR-0039, the engine, not the caller, decides.

**2. The value is allow-listed, not passed through.** Only libpq's six values
(`disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full`) are accepted. The
existing delimiter guard would pass `requre` into the DSN, where libpq rejects it at
connect time at best. A typo must fail where the operator can read the message, never
degrade to plaintext.

**3. An option key PDO would discard is an error.** `PdoConnection::fromConfig()`
partitions the options: integer keys go to PDO, the two `sslmode` spellings go to the
DSN, and anything else raises `InvalidDsnComponentException` naming the connection and
the keys. The operator who wrote `search_path` believes it is in force; silence is what
let this class of defect live.

**4. Compliance verifies the effective connection.** `connectionUsesTls()` counts
`sslmode` only for PostgreSQL, because only there does it reach the driver. The
`RuntimeVerifier` remediation text now gives the per-engine instruction that works
instead of one that does not.

## Consequences

A deployment that set `ssl_mode` on MySQL and believed it had TLS now fails at
connection build with an explanation. That is the intended outcome: it did not have TLS
before, and it was being told it did. Turning a silent false compliance pass into a loud
boot failure is the only correct direction for a framework aimed at regulated domains.

A deployment carrying unread string option keys (`search_path` and the like) also fails
until they are removed. Those keys never did anything.

`buildDsn()`'s new parameter is optional and trailing, so the signature change is
additive and existing callers are unaffected.

The general lesson is the one worth ratcheting: **a `@var` that widens or retypes a
value the analyser already knows is a suppression.** This ADR is the second place the
same annotation pattern hid a live defect — the first was `TimeSlotManager`, whose loops
declared `Row` objects to be arrays. Where a type needs restating, the restatement should
be a runtime narrowing that fails loudly, not a comment that fails silently.
