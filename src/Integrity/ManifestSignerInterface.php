<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use JsonException;
use Pulsar\Api\Api;
use SodiumException;

#[Api]
interface ManifestSignerInterface
{
    /**
     * @throws SodiumException
     * @throws JsonException
     */
    public function sign(IntegrityManifest $manifest): string;

    /**
     * @throws SodiumException
     * @throws JsonException
     */
    public function verify(IntegrityManifest $manifest): bool;
}
