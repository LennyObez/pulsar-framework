<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use Pulsar\Api\Api;

/**
 * Strategy for handling duplicate entries during import.
 */
#[Api(since: '1.0.0')]
enum DuplicateStrategy: string
{
    /** Skip duplicates silently. */
    case Skip = 'skip';

    /** Overwrite existing records with imported data. */
    case Overwrite = 'overwrite';

    /** Fail the entire import if any duplicate is found. */
    case Fail = 'fail';
}
