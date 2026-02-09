<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;
use Pulsar\Integrity\Exception\IntegrityException;

#[Api]
interface ManifestBuilderInterface
{
    /**
     * @param list<string> $includePaths
     * @param list<string> $excludePaths
     *
     * @throws IntegrityException
     */
    public function build(array $includePaths, array $excludePaths): IntegrityManifest;
}
