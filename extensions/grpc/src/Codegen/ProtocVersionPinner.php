<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Codegen;

use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Exception\GrpcException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Records and verifies the protoc version in build metadata.
 *
 * Ensures reproducible builds by pinning the protoc version used for code
 * generation. The version is stored in a JSON metadata file that can be
 * committed to version control.
 */
#[Internal(reason: 'Protoc version pinning for reproducible builds')]
final class ProtocVersionPinner
{
    private const string METADATA_KEY = 'protoc_version';
    private const string PINNED_AT_KEY = 'protoc_pinned_at';

    /**
     * Record the protoc version in the build metadata file.
     */
    public function recordVersion(string $version, string $manifestPath): void
    {
        $data = $this->loadManifest($manifestPath);
        $data[self::METADATA_KEY] = $version;
        $data[self::PINNED_AT_KEY] = date('c');
        $this->saveManifest($manifestPath, $data);
    }

    /**
     * Verify that the current protoc version matches the pinned version.
     *
     * Returns true if no version is pinned yet (first run) or if the
     * versions match.
     */
    public function verifyVersion(string $currentVersion, string $manifestPath): bool
    {
        $data = $this->loadManifest($manifestPath);
        $pinnedVersion = $data[self::METADATA_KEY] ?? null;

        if (!is_string($pinnedVersion) || $pinnedVersion === '') {
            return true;
        }

        return $pinnedVersion === $currentVersion;
    }

    /**
     * Get the pinned protoc version, or null if not pinned.
     */
    public function getPinnedVersion(string $manifestPath): ?string
    {
        $data = $this->loadManifest($manifestPath);
        /** @var mixed $version */
        $version = $data[self::METADATA_KEY] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(string $manifestPath): array
    {
        if (!file_exists($manifestPath)) {
            return [];
        }

        $content = file_get_contents($manifestPath);

        if (!is_string($content) || $content === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveManifest(string $manifestPath, array $data): void
    {
        $dir = dirname($manifestPath);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw GrpcException::cannotCreateDirectory($dir);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($manifestPath, $json . "\n");
    }
}
