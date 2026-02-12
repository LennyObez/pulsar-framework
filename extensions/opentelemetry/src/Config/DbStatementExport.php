<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use Pulsar\Api\Api;

/**
 * Controls how database statements are exported in span attributes.
 */
#[Api(since: '1.0.0')]
enum DbStatementExport: string
{
    /** Do not export SQL statements. */
    case None = 'none';

    /** Export a one-way hash of the statement (safe for regulated environments). */
    case Hash = 'hash';

    /** Export the full SQL statement (force-disabled in production mode). */
    case Full = 'full';
}
