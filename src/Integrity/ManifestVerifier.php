<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Internal;
use Pulsar\Integrity\Exception\IntegrityException;

use function hash_file;
use function is_file;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies an integrity manifest against the current filesystem state.
 *
 * Detects modified files (hash mismatch), missing files (no longer on disk),
 * and added files (covered by the manifest's scope but absent from it). All
 * three fail: a manifest that tolerates additions cannot detect a dropped
 * webshell, which is the attack this control exists to catch.
 *
 * Additions are found by re-running the manifest's own scope, so the verifier
 * looks exactly where the builder looked — no wider, which would flag untracked
 * files as tampering, and no narrower, which would leave somewhere to hide.
 */
#[Internal]
final readonly class ManifestVerifier implements ManifestVerifierInterface
{
    public function __construct(
        private string $basePath,
    ) {}

    /**
     * Verify an integrity manifest against the current filesystem.
     *
     * Checks every manifest entry for existence and hash correctness, and
     * rediscovers the manifest's scope to find files that were added to it.
     * The result passes only when nothing was modified, removed or added.
     *
     * @throws IntegrityException If the manifest declares no scope, leaving the
     *         set of files that ought to exist undefined.
     */
    public function verify(IntegrityManifest $manifest): VerificationResult
    {
        if ($manifest->scope === null) {
            throw IntegrityException::scopeMissing();
        }

        $files = [];
        $verified = 0;
        $modified = 0;
        $missing = 0;
        $added = 0;

        $manifestPaths = [];

        foreach ($manifest->entries as $entry) {
            $manifestPaths[$entry->path] = true;

            $absolutePath = $this->absolutePath($entry->path);

            if (!is_file($absolutePath)) {
                $files[] = new FileVerificationResult(
                    path: $entry->path,
                    status: FileVerificationStatus::Missing,
                    expectedHash: $entry->hash,
                    actualHash: null,
                );
                $missing++;
                continue;
            }

            $actualHash = hash_file($manifest->algorithm, $absolutePath);

            if ($actualHash === false) {
                // Treat unreadable files as modified
                $files[] = new FileVerificationResult(
                    path: $entry->path,
                    status: FileVerificationStatus::Modified,
                    expectedHash: $entry->hash,
                    actualHash: null,
                );
                $modified++;
                continue;
            }

            if ($actualHash !== $entry->hash) {
                $files[] = new FileVerificationResult(
                    path: $entry->path,
                    status: FileVerificationStatus::Modified,
                    expectedHash: $entry->hash,
                    actualHash: $actualHash,
                );
                $modified++;
                continue;
            }

            $files[] = new FileVerificationResult(
                path: $entry->path,
                status: FileVerificationStatus::Verified,
                expectedHash: $entry->hash,
                actualHash: $actualHash,
            );
            $verified++;
        }

        foreach ($manifest->scope->discover($this->basePath) as $path) {
            if (isset($manifestPaths[$path])) {
                continue;
            }

            $actualHash = hash_file($manifest->algorithm, $this->absolutePath($path));

            $files[] = new FileVerificationResult(
                path: $path,
                status: FileVerificationStatus::Added,
                expectedHash: null,
                actualHash: $actualHash !== false ? $actualHash : null,
            );
            $added++;
        }

        return new VerificationResult(
            passed: $modified === 0 && $missing === 0 && $added === 0,
            verified: $verified,
            modified: $modified,
            missing: $missing,
            added: $added,
            files: $files,
        );
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
