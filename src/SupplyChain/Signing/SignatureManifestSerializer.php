<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Signing;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Serializes and deserializes SignatureManifest objects to/from JSON.
 */
#[Internal(reason: 'Serialization implementation detail')]
final readonly class SignatureManifestSerializer
{
    /**
     * Serialize a list of manifests to JSON.
     *
     * @param list<SignatureManifest> $manifests
     * @return string JSON-encoded manifest array
     */
    #[NoDiscard]
    public function serialize(array $manifests): string
    {
        $entries = [];

        foreach ($manifests as $manifest) {
            $entries[] = [
                'artifact_path' => $manifest->artifactPath,
                'signature' => $manifest->signature,
                'public_key' => $manifest->publicKey,
                'timestamp' => $manifest->timestamp->format('c'),
                'algorithm' => $manifest->algorithm,
            ];
        }

        return json_encode(
            ['signatures' => $entries, 'schema_version' => 1],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Deserialize JSON into a list of SignatureManifest objects.
     *
     * @return list<SignatureManifest>
     */
    #[NoDiscard]
    public function deserialize(string $json): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        $manifests = [];

        /** @var mixed $rawSignatures */
        $rawSignatures = $data['signatures'] ?? null;
        $signatures = is_array($rawSignatures) ? $rawSignatures : [];

        /** @var mixed $entry */
        foreach ($signatures as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var mixed $rawArtifactPath */
            $rawArtifactPath = $entry['artifact_path'] ?? null;
            $artifactPath = is_string($rawArtifactPath) ? $rawArtifactPath : '';
            /** @var mixed $rawSignature */
            $rawSignature = $entry['signature'] ?? null;
            $signature = is_string($rawSignature) ? $rawSignature : '';
            /** @var mixed $rawPublicKey */
            $rawPublicKey = $entry['public_key'] ?? null;
            $publicKey = is_string($rawPublicKey) ? $rawPublicKey : '';
            /** @var mixed $rawTimestamp */
            $rawTimestamp = $entry['timestamp'] ?? null;
            $timestamp = is_string($rawTimestamp)
                ? new DateTimeImmutable($rawTimestamp)
                : new DateTimeImmutable();
            /** @var mixed $rawAlgorithm */
            $rawAlgorithm = $entry['algorithm'] ?? null;
            $algorithm = is_string($rawAlgorithm) ? $rawAlgorithm : 'ed25519';

            $manifests[] = new SignatureManifest(
                artifactPath: $artifactPath,
                signature: $signature,
                publicKey: $publicKey,
                timestamp: $timestamp,
                algorithm: $algorithm,
            );
        }

        return $manifests;
    }
}
