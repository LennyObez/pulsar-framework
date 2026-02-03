<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Runtime;

use Pulsar\Api\Internal;

/**
 * Minimal filesystem introspection for deploy checks.
 *
 * Allows deployment-time file checks to be tested without real filesystem access.
 */
#[Internal]
interface FilesystemReaderInterface
{
    /**
     * Check whether a file is readable.
     *
     * @see is_readable()
     */
    public function isReadable(string $path): bool;
}
