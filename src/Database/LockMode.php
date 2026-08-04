<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;

/**
 * Row-level lock modes for `SELECT … FOR UPDATE` / `FOR SHARE`.
 *
 * Lives in the database layer rather than in an ORM: row locking is a property of the
 * engine and of the transaction, and code that never touches the ORM still needs to ask
 * for one.
 *
 * Not every engine can honour every mode — SQLite has no row-level locking at all — so a
 * caller that depends on the lock actually being taken must ask
 * {@see Dialect\DialectInterface::supportsRowLocking()} first. A lock silently not taken
 * is worse than one refused: the transaction proceeds believing it holds something.
 *
 * @api
 */
#[Api(since: '1.0.0')]
enum LockMode: string
{
    case None = 'none';
    case ForUpdate = 'for_update';
    case ForShare = 'for_share';
}
