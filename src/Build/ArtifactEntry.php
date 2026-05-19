<?php

declare(strict_types=1);

namespace Pulsar\Build;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * A single build artifact entry with its path, hash, and size.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ArtifactEntry
{
    public function __construct(
        public string $path,
        public string $hash,
        public int $size,
    ) {}

    /**
     * Create from array data.
     *
     * @param array{
     *     path?: string,
     *     hash?: string,
     *     size?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'] ?? '',
            hash: $data['hash'] ?? '',
            size: $data['size'] ?? 0,
        );
    }

    /**
     * Export to array representation.
     *
     * @return array{path: string, hash: string, size: int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'hash' => $this->hash,
            'size' => $this->size,
        ];
    }
}
