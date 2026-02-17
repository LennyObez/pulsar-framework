<?php

declare(strict_types=1);

namespace Pulsar\Dev\HotReload;

use Pulsar\Api\Internal;

/**
 * Types of file system changes the watcher detects.
 */
#[Internal]
enum FileChangeType: string
{
    case Created = 'created';
    case Modified = 'modified';
    case Deleted = 'deleted';
}
