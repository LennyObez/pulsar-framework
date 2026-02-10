<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Verifies theme package integrity (SHA-256) and authenticity (Ed25519 signature).
 */
#[Api(since: '1.0.0')]
interface ThemeProvenanceVerifierInterface
{
    /**
     * Verify the provenance of a theme archive.
     *
     * Checks the SHA-256 hash of the archive and, if a signature file is present,
     * verifies the Ed25519 signature against the configured trusted public keys.
     *
     * @param string $archivePath Filesystem path to the theme archive
     * @param string|null $signaturePath Filesystem path to the detached Ed25519 signature, or null
     */
    public function verify(string $archivePath, ?string $signaturePath = null): ProvenanceResult;
}
