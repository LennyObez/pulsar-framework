<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Signing;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;
use function preg_match;
use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * Configuration for release-artifact signing and verification.
 *
 * The artifact directory holds the release files to sign or verify; the
 * manifest path is where the Ed25519 signature manifest is written and read
 * back. Both are read from the `signing` section of config/supply-chain.php
 * and default to the framework conventions.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SigningConfig
{
    /**
     * @param string $artifactDir Path (relative to the project root, or absolute)
     *        to the directory of release artifacts to sign or verify
     * @param string $manifestPath Path (relative to the project root, or absolute)
     *        to the Ed25519 signature manifest
     */
    public function __construct(
        public string $artifactDir = 'dist',
        public string $manifestPath = 'signatures.json',
    ) {}

    /**
     * @param array<string, mixed> $data The `signing` section of config/supply-chain.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $artifactDir */
        $artifactDir = $data['artifact_dir'] ?? null;
        /** @var mixed $manifestPath */
        $manifestPath = $data['manifest_path'] ?? null;

        return new self(
            artifactDir: is_string($artifactDir) && $artifactDir !== '' ? $artifactDir : 'dist',
            manifestPath: is_string($manifestPath) && $manifestPath !== '' ? $manifestPath : 'signatures.json',
        );
    }

    /**
     * Absolute artifact directory, resolving a relative value against the root.
     */
    #[NoDiscard]
    public function resolvedArtifactDir(string $projectRoot): string
    {
        return $this->resolve($this->artifactDir, $projectRoot);
    }

    /**
     * Absolute manifest path, resolving a relative value against the root.
     */
    #[NoDiscard]
    public function resolvedManifestPath(string $projectRoot): string
    {
        return $this->resolve($this->manifestPath, $projectRoot);
    }

    private function resolve(string $path, string $projectRoot): string
    {
        return self::isAbsolute($path)
            ? $path
            : rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
