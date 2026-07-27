<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use Pulsar\Api\Internal;
use Throwable;

use function str_contains;

/**
 * Classifies database errors so the CMS can degrade gracefully when its schema
 * is simply not installed (a fresh / unmigrated database) without masking a
 * genuine database failure.
 *
 * The CMS owns the catch-all content route, so a missing table must resolve to a
 * 404 (or the welcome page) rather than turning the homepage — and every
 * unmatched route — into a 500. A real outage, permission error, or malformed
 * query is NOT a missing-table error and must still surface.
 */
#[Internal(reason: 'CMS persistence error classification')]
final class SchemaErrors
{
    /**
     * Whether the error means a queried table does not exist (schema not
     * migrated). Detected from the driver error text, which carries the
     * SQLSTATE: 42S02 (MySQL "base table not found") / 42P01 (PostgreSQL
     * "undefined table"), or the SQLite "no such table" message. Reads the
     * underlying PDO exception when the error is wrapped (e.g. DatabaseException).
     */
    public static function isMissingTable(Throwable $error): bool
    {
        $message = $error->getPrevious()?->getMessage() ?? $error->getMessage();

        return str_contains($message, '42S02')
            || str_contains($message, '42P01')
            || str_contains($message, 'no such table');
    }
}
