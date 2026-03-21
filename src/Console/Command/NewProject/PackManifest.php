<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function array_diff;
use function array_keys;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Readonly DTO representing a scaffolding pack manifest (pack.json).
 *
 * Each scaffolding pack ships a `pack.json` file that describes its metadata,
 * compliance coverage, file templates, and post-install hooks.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PackManifest
{
    /**
     * @param string               $name                  Pack identifier (e.g., "banking")
     * @param string               $description           Human-readable pack description
     * @param string               $version               Pack version (semver)
     * @param string               $requiredPulsarVersion Minimum Pulsar version constraint
     * @param list<string>         $compliancePresets     Control framework names this pack supports
     * @param array<string,string> $files                 Template file mappings (source => target)
     * @param list<string>         $postInstallCommands   Shell commands to run after installation
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $version,
        public string $requiredPulsarVersion,
        public array $compliancePresets,
        public array $files,
        public array $postInstallCommands,
    ) {}

    /**
     * Create a manifest from a decoded pack.json array.
     *
     * @param array<string, mixed> $data Decoded JSON data
     *
     * @throws InvalidArgumentException If required fields are missing or invalid
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $required = ['name', 'description', 'version', 'requiredPulsarVersion'];
        $missing = array_diff($required, array_keys($data));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Pack manifest is missing required fields: %s',
                implode(', ', $missing),
            ));
        }

        $name = $data['name'];
        $description = $data['description'];
        $version = $data['version'];
        $requiredPulsarVersion = $data['requiredPulsarVersion'];

        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException('Pack manifest field "name" must be a non-empty string.');
        }

        if (!is_string($description) || $description === '') {
            throw new InvalidArgumentException('Pack manifest field "description" must be a non-empty string.');
        }

        if (!is_string($version) || $version === '') {
            throw new InvalidArgumentException('Pack manifest field "version" must be a non-empty string.');
        }

        if (!is_string($requiredPulsarVersion) || $requiredPulsarVersion === '') {
            throw new InvalidArgumentException('Pack manifest field "requiredPulsarVersion" must be a non-empty string.');
        }

        $compliancePresets = $data['compliancePresets'] ?? [];
        if (!is_array($compliancePresets)) {
            throw new InvalidArgumentException('Pack manifest field "compliancePresets" must be an array.');
        }

        $files = $data['files'] ?? [];
        if (!is_array($files)) {
            throw new InvalidArgumentException('Pack manifest field "files" must be an array.');
        }

        $postInstallCommands = $data['postInstallCommands'] ?? [];
        if (!is_array($postInstallCommands)) {
            throw new InvalidArgumentException('Pack manifest field "postInstallCommands" must be an array.');
        }

        /** @var list<string> $compliancePresets */
        /** @var list<string> $postInstallCommands */

        /** @var array<string, string> $typedFiles */
        $typedFiles = [];
        foreach ($files as $key => $value) {
            $stringKey = is_string($key) ? $key : (string) $key;
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException(sprintf('Pack manifest "files" value for key "%s" must be a string.', $stringKey));
            }
            $typedFiles[$stringKey] = (string) $value;
        }

        return new self(
            name: $name,
            description: $description,
            version: $version,
            requiredPulsarVersion: $requiredPulsarVersion,
            compliancePresets: $compliancePresets,
            files: $typedFiles,
            postInstallCommands: $postInstallCommands,
        );
    }
}
