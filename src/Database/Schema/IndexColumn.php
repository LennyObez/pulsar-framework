<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * One column of an index, with the ordering it is stored in.
 *
 * Most index columns need nothing but a name, and {@see IndexOperations::ensure()} takes
 * plain strings for those. This exists for the ones where the order the rows are stored in
 * is the point: a listing that reads "newest first" scans a descending index forwards and
 * an ascending one backwards, and only the first can stop early.
 *
 * `$nullsLast` is separate from the direction because the engines disagree about the
 * default. PostgreSQL sorts NULLs first under `DESC` and last under `ASC`; SQLite sorts
 * them first either way. A column that is nullable and ordered therefore has three
 * distinguishable states, not two, and leaving it null here means "whatever this engine
 * does" rather than any particular answer.
 *
 * Not every engine can express the ordering. MySQL has honoured `DESC` in an index since
 * 8.0 and has no `NULLS` clause at all; ask
 * {@see \Pulsar\Database\Dialect\DialectInterface::supportsNullsOrdering()} before relying
 * on that half. Getting it wrong costs a scan direction, never a wrong row.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IndexColumn
{
    public function __construct(
        public string $name,
        public bool $descending = false,
        public ?bool $nullsLast = null,
    ) {}

    /**
     * Descending, which is the reason this class is usually reached for.
     */
    public static function desc(string $name, ?bool $nullsLast = null): self
    {
        return new self($name, descending: true, nullsLast: $nullsLast);
    }
}
