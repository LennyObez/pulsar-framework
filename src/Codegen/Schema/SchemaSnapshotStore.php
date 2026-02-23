<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Reads and writes schema snapshots to disk.
 *
 * Default storage path: `database/.schema-snapshot.json`.
 */
#[Api(since: '1.0.0')]
final readonly class SchemaSnapshotStore implements SchemaSnapshotStoreInterface
{
    public function __construct(
        private string $path,
    ) {}

    /**
     * Load a snapshot from disk, or null if no snapshot file exists.
     */
    #[NoDiscard]
    public function load(): ?SchemaSnapshot
    {
        if (! file_exists($this->path)) {
            return null;
        }

        $json = file_get_contents($this->path);

        if ($json === false) {
            throw new RuntimeException("Failed to read snapshot file: {$this->path}");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException("Invalid snapshot file format: {$this->path}");
        }

        /** @var array<string, mixed> $data */

        return SchemaSnapshot::fromArray($data);
    }

    /**
     * Save a snapshot to disk with deterministic formatting.
     */
    public function save(SchemaSnapshot $snapshot): void
    {
        $dir = dirname($this->path);

        if (! is_dir($dir)) {
            if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $json = json_encode(
            $snapshot->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $result = file_put_contents($this->path, $json . "\n");

        if ($result === false) {
            throw new RuntimeException("Failed to write snapshot file: {$this->path}");
        }
    }

    /**
     * Check if a snapshot file exists on disk.
     */
    #[NoDiscard]
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * Get the configured file path.
     */
    #[NoDiscard]
    public function getPath(): string
    {
        return $this->path;
    }
}
