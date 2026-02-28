<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Strategy for resolving duplicate entities during import.
 *
 * @psalm-api Public enum referenced by ImportExportServiceInterface and
 *            MediaBundleImporter; admin import-policy controls expose its cases.
 */
#[Api(since: '1.0.0')]
enum DuplicateResolutionPolicy: string
{
    /** Overwrite existing entities with imported data. */
    case Replace = 'replace';

    /** Import duplicates as new entities with fresh IDs. */
    case ImportAsNew = 'import_as_new';

    /** Skip entities that already exist. */
    case Skip = 'skip';

    /** Merge imported fields into existing entities, keeping existing values for unset fields. */
    case Merge = 'merge';
}
