# ADR-0039: Schema questions belong to the dialect, not to the caller

## Status

Accepted. Extends [ADR-0037](0037-sql-server-as-a-fourth-driver.md), which recorded that
`Driver` must stop being the dispatch axis, by giving four of the questions that forced
callers to use it an answer they can ask for instead.

## Context

`Driver` answers one question: which PDO driver opened this connection. It is an identity.
A caller that writes `match ($driver)` has taken on a second job — knowing every engine the
framework will ever support — and an exhaustive match throws `UnhandledMatchError` the
first time it meets a case that did not exist when it was written. That is what makes
adding an engine a breaking change rather than an additive one, and it is why
`DriverDispatchRatchetTest` allows the count of such files to shrink and never to grow.

The ratchet caught `20260805000001_totp_replay_guard_drop_purpose`, a migration naming an
engine twenty-two times. Inspection showed the dispatch was not laziness. The migration was
asking four questions that genuinely have different answers per engine and that no existing
abstraction could answer:

1. **Does this table exist?** SQLite keeps the answer in `sqlite_master`, MySQL in
   `information_schema.tables`, PostgreSQL in `pg_class`.
2. **Does this column exist?** Likewise, with the additional trap that
   `information_schema.columns` queried without a schema predicate resolves against
   whatever the search path offers — on PostgreSQL's stock path (`"$user", public`) a role
   owning a same-named schema is enough to make the guard answer for a different table.
3. **Does this table carry a primary key?** Three catalogues, three shapes.
4. **How do I leave one row per key?** SQLite has `rowid`, PostgreSQL has `ctid`, and
   MySQL has neither.

The fourth is the interesting one. It is not that MySQL spells the operation differently —
it is that MySQL *cannot express it at all* when the duplicate rows agree on every column,
because nothing separates them. Two rows that are byte-for-byte identical cannot be told
apart by any predicate, so no `DELETE` can keep exactly one.

## Decision

**Four methods join `DialectInterface`**, alongside the existing `compileIndexExists()`:

| Method | Binds | Answers |
| --- | --- | --- |
| `compileTableExists()` | `table` | whether the table exists |
| `compileColumnExists()` | `table`, `column` | whether the column exists |
| `compilePrimaryKeyExists()` | `table` | whether a primary key exists |
| `compileCollapseDuplicates()` | — | a statement leaving one row per key, **or null** |

Each returns a query yielding a single row with one column, `c`, holding a count. None is
given a default in `AbstractDialect`: there is no reasonable one, and an engine added
without an answer would silently report every table absent, which reads as "create it" and
fails on the duplicate.

**`compileCollapseDuplicates()` may return null**, and that is the substantive part of this
decision. It takes an optional `$discriminator` — a column whose values order the
duplicates. Given one, every engine can express the operation, because that column supplies
the total order. Without one, the engine's own per-row identity has to serve, and only two
of the three expose one. MySQL answers null.

Null is a statement about what the engine *can express*, not about which engine it is. That
distinction is the whole point: a caller that branches on null is portable to a fourth
engine without modification, and a caller that branches on `Driver::MySQL` is not.

**Two capabilities join `SchemaCapabilities`**: `supportsDroppingKeyColumn()` and
`supportsAddPrimaryKey()`. Both are false only for SQLite today, and neither is implied by
`supportsDropColumn()` — SQLite has supported `DROP COLUMN` since 3.35.0 and still refuses
it for a column the primary key names, at every version.

**`TableIntrospector` joins `src/Database/Schema/`**, taking a connection and asking it for
its dialect, in the shape `IndexOperations` already established. Migrations receive a
connection and nothing else, so a collaborator they cannot obtain is a collaborator they
cannot use.

## Consequences

The migration that prompted this names no engine at all. It reads:

```php
if (!$capabilities->supportsDroppingKeyColumn())    → rebuild the table
elseif (!$capabilities->supportsTransactionalDdl()) → one ALTER, or a keyless window
else                                                → drop the column; the key returns next step
```

Three behaviours chosen by capability. A fourth driver adds an answer to
`SchemaCapabilities` and the migration is untouched.

**This is a breaking change to an `#[Api]` interface.** A third-party `DialectInterface`
implementation stops compiling until it supplies the four methods. Within the framework the
change is contained: the ORM's dialects implement a different interface of the same name and
are unaffected.

**What this does not do.** It does not give the framework a portable table rebuild. Where an
engine cannot drop a key column, the caller still writes the `CREATE`/`INSERT`/`DROP`/
`RENAME` sequence itself, because the replacement table's shape is knowledge only the caller
has. Generalising that needs a schema definition the rebuilder can read, which is a larger
design and is not attempted here.

**Measured, not assumed.** `SqliteDialect` carried a comment stating that a pragma cannot be
given its table name through a binding. Against SQLite 3.53.2 that is true of the statement
form — `PRAGMA table_info(:table)` is a syntax error at the placeholder — and false of the
table-valued form, `pragma_table_info(:table)`, which the new `compileColumnExists()` and
`compilePrimaryKeyExists()` both use. The comment has been corrected.

The four methods are verified against MySQL 8.0, PostgreSQL 16 and SQLite through
`tools/docker/compose.yaml`: 63 contract tests, 146 assertions, no failures. That matters
more than usual here — of the three engines, only SQLite runs without a container, so a
suite green on a developer's machine says nothing about two thirds of this surface.
