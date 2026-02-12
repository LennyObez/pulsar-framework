<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;

/**
 * Verifies plugin package integrity (SHA-256) and authenticity (Ed25519 signature).
 */
#[Api(since: '1.0.0')]
interface PluginProvenanceVerifierInterface
{
    /**
     * Verify the provenance of a plugin archive.
     *
     * Checks the SHA-256 hash of the archive and, if a signature file is present,
     * verifies the Ed25519 signature against the configured trusted public keys.
     *
     * @param string $archivePath Filesystem path to the plugin archive
     * @param string|null $signaturePath Filesystem path to the detached Ed25519 signature, or null
     */
    public function verify(string $archivePath, ?string $signaturePath = null): ProvenanceResult;
}
