<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Supported column types for schema DDL operations.
 * @api
 */
#[Api(since: '1.0.0')]
enum SchemaColumnType: string
{
    case String = 'string';

    /**
     * Variable-length text, and on MySQL a narrow one.
     *
     * Compiles to `TEXT` on all three engines, and that single word buys three different
     * ceilings: about a gibibyte on PostgreSQL, about a gibibyte on SQLite, and 65,535
     * *bytes* on MySQL. Bytes, not characters — a utf8mb4 column runs out somewhere
     * between 16,383 and 65,535 characters depending on which ones they are.
     *
     * The MySQL ceiling is not a soft one either way it falls. With `sql_mode` at the
     * shipped default an oversized write raises error 1406 and the statement fails; with
     * strict mode switched off the value is truncated and the row is quietly wrong.
     *
     * Use this for text a person typed into a field somebody sized. When the width is
     * decided by data rather than by a form — a serialised payload, a saga context, an
     * integration event body, a stack trace — use {@see BigText} instead.
     */
    case Text = 'text';

    /**
     * Variable-length text at the widest ceiling the engine offers.
     *
     * Compiles to `LONGTEXT` on MySQL, which holds four gibibytes against the 64 KiB of
     * {@see Text}, and to `TEXT` on PostgreSQL and SQLite, which is already the widest
     * either of them has and needs no second spelling. The case is named for what the
     * caller gets — a ceiling no payload will reach — rather than for the MySQL keyword
     * that happens to deliver it, in the same way {@see BigInt} widens {@see Integer}.
     *
     * What it costs on MySQL, so the choice can be made on evidence rather than on
     * caution: a `LONGTEXT` value carries a 4-byte length prefix where `TEXT` carries 2,
     * and an index on the column must state a prefix length — which is equally true of
     * `TEXT`, so nothing is given up there.
     */
    case BigText = 'bigtext';

    case Integer = 'integer';
    case SmallInt = 'smallint';
    case BigInt = 'bigint';
    case Float = 'float';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case DateTime = 'datetime';
    case Date = 'date';
    case Time = 'time';
    case Json = 'json';
    case Uuid = 'uuid';
    case Binary = 'binary';
    case Enum = 'enum';
}
