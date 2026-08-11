# ADR-0037: Add SQL Server as a fourth `Driver`, and keep MariaDB a variant

## Status

Proposed

## Context

`Pulsar\Database\Driver` is a backed enum with three cases — MySQL, PostgreSQL and
SQLite — and it is the axis every dialect decision turns on. Dialect-bearing code
dispatches on it in `DdlCompiler`, `SchemaCapabilities`, `DatabaseIntrospector`,
`UpsertBuilder`, `InListBuilder`, `MigrationRunner`, the ORM's `IdentifierQuoter`,
`SchemaBuilder` and `SqlCompiler`, and in roughly a hundred extension migration files.

Three properties of the existing code decide how a fourth engine can be added at all.

**The dispatch sites are exhaustive `match` expressions with no `default` arm.** That is
what makes a fourth case tractable: PHPStan and Psalm report every site that stops being
exhaustive, so the work list is produced by the analysers rather than assembled by hand.
The exceptions are real and must be closed first, because they are the sites where a new
case would silently receive another engine's SQL instead of a compile-time error:
`DdlCompiler::mapType()` has six sub-matches with a `default` arm, `quoteDefaultValue()`
has one, `compileEnumType()` is an `if` rather than a `match`, and the ORM's
`SchemaDdlCompiler` dispatches on `$dialect->name()` — a **string**, which no analyser can
check for exhaustiveness at all.

**The engines are now verifiable.** Until the driver contract suite existed, a dialect was
asserted by comparing generated SQL to a string a developer had written, and no statement
generated for MySQL or PostgreSQL had ever been executed by MySQL or PostgreSQL. A fourth
dialect added under those conditions would have been a fourth string generator. The
contract suite executes generated DDL, upserts and IN-lists against a live server per
engine, so a new dialect can now be held to the same standard as the existing three.

**Not every engine belongs on this axis.** Two candidates were considered and placed
elsewhere, and recording why is the point of this section.

## Decision

### SQL Server becomes `Driver::SqlServer`

It is a relational engine reached through PDO (`pdo_sqlsrv`, DSN `sqlsrv:`), so it fits
the abstraction the enum describes. Its dialect diverges from all three existing cases in
ways the compilers must express: bracket-delimited identifiers, `OFFSET … FETCH` instead
of `LIMIT`, `MERGE` for upsert, `SCOPE_IDENTITY()` for the last inserted key, `NVARCHAR`
and `VARBINARY(MAX)` type mapping, `IDENTITY(1,1)` for auto-increment, and the `sys.*`
catalogue views for introspection.

### MariaDB stays a `DriverVariant`, and gets wired up

MariaDB speaks the MySQL wire protocol and connects through `pdo_mysql` with a `mysql:`
DSN. At the layer `Driver` models — which PDO driver opens the connection — MariaDB _is_
MySQL, so a separate enum case would misdescribe it.

`DriverVariant` already exists for exactly this, with a `detect()` that reads the server's
`VERSION()` string, and `MariaDbDialect` is already written and tested. What is missing is
the wiring: `IdentifierQuoter::dialectFor()` takes only a `Driver` and never consults the
variant, so a real MariaDB server is handed `MySqlDialect` and never emits `RETURNING`,
which MariaDB has supported since 10.5. The fix belongs to the variant axis, not this one:
`ConnectionInterface` gains a `variant()` that reports what the server actually answered,
and dialect selection consults it.

Version floors follow the same axis. MariaDB 10.2 is newer than MySQL 8.0 despite the
lower number, so a capability probe that applied MySQL's floor to a MariaDB server would
report every MariaDB as incapable — which is why `SchemaCapabilities` takes a floor per
family and reads the family from the live version string rather than from a caller's hint.

### MongoDB and Redis-as-a-datastore are not `Driver` cases

Neither is reached through PDO, and the stack below `Driver` is SQL-shaped throughout:
`ConnectionInterface::select(string $sql, …)`, `Statement`, `DdlCompiler`,
`MigrationRunner`. A `Driver::MongoDB` arm would have to answer what SQL MongoDB uses for
`CREATE TABLE`, a question with no answer. They belong to a separate document-store
abstraction, decided in its own ADR.

For Redis specifically there is a second consideration a regulated-domain framework should
not skip: the server's licence changed in 2024 from BSD to a dual RSALv2/SSPL that is not
OSI-approved, with AGPLv3 added later. Recommending it as a datastore places that licence
in a downstream user's dependency review. The client extension carries no such condition.

## Consequences

**Adding an enum case is additive for the enum and breaking for its consumers** — and
"do it before 1.0.0" is a deadline, not an answer. A framework that can never support a
new engine after its stable release has not solved the problem; it has agreed to stop.

The real fix is to make `match ($driver)` something a consumer never needs to write.

`Driver` is currently two things at once: an identity (which PDO driver opens the
connection) and the axis every behavioural decision turns on. Only the first belongs in
a consumer's hands. A consumer asking "how do I quote this identifier", "does this
support `RETURNING`", "how do I express a limit" is asking about a **capability**, and a
capability can be answered by an object. Adding an engine then means adding an
implementation, which is additive for everyone.

The pieces already exist and are pointed the wrong way: `DialectInterface` with its four
implementations lives under `extensions/orm/src/Internal/`, and `SchemaCapabilities` is
public but incomplete. The decision is therefore:

1. **Promote a dialect abstraction to the public API** — quoting, limit and offset,
   upsert, `RETURNING` support, type mapping — and make `SchemaCapabilities` complete
   enough that no behavioural question requires naming an engine.
2. **Demote `Driver` in the documentation** from dispatch axis to identity, and say
   plainly that a consumer matching on it is writing code that a future engine breaks.
3. **Guard it.** A test that fails when `Driver` is matched outside the dialect
   construction layer, in the same shape as the guards that already refuse a stray file
   at the repository root or a second copy of the composition-root list. A convention
   that is only written down is a convention that erodes.

Until that lands, adding a case remains breaking for anyone who matched, and the ordering
stands: `Driver::SqlServer` before 1.0.0. Afterwards it is additive, because there is
nothing left downstream to break.

**The `default` arms must go first.** A `default` arm compiles cleanly with a new case and
emits another engine's SQL, so every one of them is a site where the fourth dialect would
ship silently wrong. Converting them to exhaustive matches is a prerequisite, not a
cleanup, and the ORM's string-keyed dispatch must move onto the enum for the same reason.

**A container is part of the deliverable, not a follow-up.** The dialect is not finished
when it compiles; it is finished when the contract suite executes its DDL, upsert and
IN-list against a real SQL Server. The Developer edition image is free for development and
test use.

**Roughly a hundred extension migrations dispatch on `Driver`.** Each needs an arm or a
deliberate decision that the extension does not support the engine — recorded, not
implied.
