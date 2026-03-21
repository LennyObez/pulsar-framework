<?php

declare(strict_types=1);

namespace Pulsar\Build;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            hash: is_string($data['hash'] ?? null) ? $data['hash'] : '',
            size: isset($data['size']) && is_int($data['size']) ? $data['size'] : 0,
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
