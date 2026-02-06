<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;
use SodiumException;

/**
 * Signs and verifies integrity manifests using HMAC (BLAKE2b).
 *
 * Uses a subkey derived from the application master key with
 * subKeyId=6 and context='integ_sg' for domain separation.
 */
#[Internal]
final class ManifestSigner
{
    private const int SUB_KEY_ID = 6;
    private const string CONTEXT = 'integ_sg';

    private readonly string $signingKey;

    /**
     * @throws SodiumException
     */
    public function __construct(MasterKey $masterKey)
    {
        $this->signingKey = $masterKey->deriveSubKey(self::SUB_KEY_ID, self::CONTEXT);
    }

    /**
     * Compute an HMAC signature for the manifest.
     *
     * Signs the canonical JSON representation of the manifest (all fields
     * except the signature field itself).
     *
     * @throws SodiumException
     * @throws JsonException If JSON encoding fails during canonicalization
     */
    public function sign(IntegrityManifest $manifest): string
    {
        $canonical = $this->canonicalize($manifest);

        return Hmac::computeHex($canonical, $this->signingKey);
    }

    /**
     * Verify the HMAC signature of a signed manifest.
     *
     * Recomputes the HMAC from the manifest data and compares it
     * against the stored signature using constant-time comparison.
     *
     * @throws SodiumException
     * @throws JsonException If JSON encoding fails during canonicalization
     */
    public function verify(IntegrityManifest $manifest): bool
    {
        if ($manifest->signature === null) {
            return false;
        }

        $canonical = $this->canonicalize($manifest);

        return Hmac::verifyHex($canonical, $manifest->signature, $this->signingKey);
    }

    /**
     * Build a canonical JSON representation of the manifest for signing.
     *
     * Includes all fields except the signature to allow round-trip
     * sign-then-verify without circularity.
     *
     * @throws JsonException If JSON encoding fails
     */
    private function canonicalize(IntegrityManifest $manifest): string
    {
        $entries = [];

        foreach ($manifest->entries as $entry) {
            $entries[] = [
                'path' => $entry->path,
                'hash' => $entry->hash,
                'size' => $entry->size,
            ];
        }

        $data = [
            'version' => $manifest->version,
            'algorithm' => $manifest->algorithm,
            'generated_at' => $manifest->generatedAt,
            'framework_version' => $manifest->frameworkVersion,
            'entry_count' => $manifest->entryCount,
            'entries' => $entries,
        ];

        /** @var non-empty-string $json */
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $json;
    }
}
