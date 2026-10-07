<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;
use Pulsar\Integrity\Exception\IntegrityException;

#[Api(since: '1.0.0')]
interface ManifestVerifierInterface
{
    /**
     * @throws IntegrityException If the manifest does not define what it covers
     */
    public function verify(IntegrityManifest $manifest): VerificationResult;
}
