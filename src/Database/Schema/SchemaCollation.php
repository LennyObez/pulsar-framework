<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * How a table compares the text it stores.
 *
 * A collation decides two things at once: the order text sorts in, and — the half with
 * teeth — whether two values differing only in case or in accent are the same value.
 * The second is not a display concern. `PRIMARY KEY` and `UNIQUE` are enforced through
 * the column's collation, so under a case-insensitive one `order-42` and `ORDER-42`
 * collide on insert, and a lookup by identifier returns a row nobody asked for.
 *
 * MySQL is why this has to be stated rather than assumed. Its shipped defaults are
 * case- and accent-insensitive — `utf8mb4_0900_ai_ci` on MySQL 8.0, `utf8mb4_general_ci`
 * and `latin1_swedish_ci` on the versions and forks beside it — so a table created with
 * no collation clause silently gets the insensitive behaviour. PostgreSQL and SQLite
 * default the other way.
 *
 * ## Why a collation and not a full set of table options
 *
 * The hand-written DDL this replaced also carried `ENGINE=InnoDB` and
 * `DEFAULT CHARSET=utf8mb4`. Neither is offered here, for a reason per clause:
 *
 * - **Character set** is implied. MySQL derives a table's character set from its
 *   collation when only the collation is given, and every collation belongs to exactly
 *   one character set — asking for `Exact` already asks for utf8mb4. A separate option
 *   could only agree or contradict.
 * - **Storage engine** is neither portable nor load-bearing. InnoDB has been MySQL's
 *   default since 5.5 and MariaDB's since 10.2, and a server configured to default
 *   elsewhere would be creating non-transactional tables for the whole application, not
 *   for the five that named the engine — a per-table clause could not rescue that
 *   deployment, and a connection preflight is where it should be caught. PostgreSQL and
 *   SQLite have no such concept at all, so the option would be vocabulary this layer
 *   exists to hide.
 *
 * Only the intent is named here; which clause delivers it belongs to
 * {@see DdlCompiler}, the one class whose job is knowing the engines.
 * @api
 */
#[Api(since: '1.0.0')]
enum SchemaCollation: string
{
    /**
     * Two values are equal only when they are the same text, byte for byte.
     *
     * Case counts and accents count: `abc`, `ABC` and `äbc` are three distinct keys. That
     * is what a column holding an identifier needs — a saga id, a queue name, an event
     * type, a payload hash — and on MySQL it is exactly what the default does not give.
     *
     * Honoured by engine:
     *
     * - **MySQL/MariaDB** emit `COLLATE=utf8mb4_bin` on the table and `COLLATE
     *   utf8mb4_bin` on a character column, which is where the request changes behaviour.
     * - **PostgreSQL** emits nothing, and is already correct: every collation `initdb`
     *   creates is deterministic, so equality on `text` falls through to a byte
     *   comparison whenever the locale's ordering rules call two values a tie. `'A'` and
     *   `'a'` are distinct, and a key rejects the second. There is also no table-level
     *   `COLLATE` clause in PostgreSQL to emit.
     * - **SQLite** emits nothing, and is already correct: the default collating sequence
     *   is `BINARY`, which is that same byte comparison. `NOCASE` has to be asked for by
     *   name, so silence here cannot be mistaken for it.
     *
     * The counterpart — a case-insensitive default — is deliberately absent. PostgreSQL
     * has no table-level spelling for it and SQLite's `NOCASE` folds ASCII only, so the
     * layer would be accepting a request it can honour on one engine of three, and a
     * promise kept in one deployment out of three is worse than no vocabulary at all.
     * Case-insensitive matching belongs in the query or in a generated column, where it
     * is visible.
     */
    case Exact = 'exact';
}
