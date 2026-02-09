<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface ManifestVerifierInterface
{
    public function verify(IntegrityManifest $manifest): VerificationResult;
}
