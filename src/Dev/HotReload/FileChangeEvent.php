<?php

declare(strict_types=1);

namespace Pulsar\Dev\HotReload;

use Pulsar\Api\Internal;

use function basename;

/**
 * Represents a detected file system change.
 */
#[Internal]
final readonly class FileChangeEvent
{
    public function __construct(
        public string $path,
        public FileChangeType $type,
        public float $detectedAt,
    ) {}

    /**
     * Get the file basename.
     */
    public function filename(): string
    {
        return basename($this->path);
    }

    /**
     * Serialize for WebSocket broadcast.
     *
     * @return array{path: string, type: string, detected_at: float}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type->value,
            'detected_at' => $this->detectedAt,
        ];
    }
}
