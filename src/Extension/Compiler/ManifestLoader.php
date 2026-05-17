<?php

declare(strict_types=1);

namespace Pulsar\Extension\Compiler;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function file_exists;
use function is_array;
use function is_file;
use function sprintf;

/**
 * Loads a compiled extension manifest from a PHP file.
 * @api
 */
#[Api(since: '1.0.0')]
final class ManifestLoader
{
    /**
     * Load a compiled extension manifest from a PHP file.
     *
     * The file must return an array compatible with CompiledExtensionManifest::fromArray().
     *
     * @throws RuntimeException If the file does not exist or does not return an array
     */
    #[NoDiscard]
    public function load(string $path): CompiledExtensionManifest
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf("Compiled manifest not found at '%s'", $path));
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new RuntimeException(sprintf("Compiled manifest at '%s' did not return an array", $path));
        }

        /** @var array<string, mixed> $data */
        return CompiledExtensionManifest::fromArray($data);
    }

    /**
     * Check if a compiled manifest file exists.
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }
}
