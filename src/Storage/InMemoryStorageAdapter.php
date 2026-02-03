<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use NoDiscard;
use Override;

use function str_starts_with;
use function strlen;
use function time;

/**
 * In-memory storage adapter for testing.
 */
final class InMemoryStorageAdapter implements StorageAdapterInterface
{
    /** @var array<string, array{content: string, metadata: ?StorageMetadata, time: int}> */
    private array $objects = [];

    #[Override]
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        $this->objects[$key] = [
            'content' => $content,
            'metadata' => $metadata,
            'time' => time(),
        ];
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key): string
    {
        if (!isset($this->objects[$key])) {
            throw StorageException::objectNotFound($key);
        }

        return $this->objects[$key]['content'];
    }

    #[Override]
    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    #[Override]
    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    #[Override]
    public function list(string $prefix = ''): array
    {
        $results = [];

        foreach ($this->objects as $key => $data) {
            if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                continue;
            }

            $results[] = new StorageObject(
                key: $key,
                size: strlen($data['content']),
                lastModified: $data['time'],
                contentType: $data['metadata']?->contentType,
            );
        }

        return $results;
    }

    #[Override]
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        return null;
    }
}
