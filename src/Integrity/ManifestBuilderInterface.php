<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;
use Pulsar\Integrity\Exception\IntegrityException;

#[Api(since: '1.0.0')]
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
