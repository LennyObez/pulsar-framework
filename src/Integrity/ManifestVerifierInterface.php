<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

#[Api]
interface ManifestVerifierInterface
{
    public function verify(IntegrityManifest $manifest): VerificationResult;
}
