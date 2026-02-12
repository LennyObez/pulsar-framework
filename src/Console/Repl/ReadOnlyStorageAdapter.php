<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageMetadata;
use Pulsar\Storage\StorageObject;

/**
 * Read-only storage adapter decorator for REPL safe mode.
 *
 * Read operations delegate to the inner adapter.
 * Write operations throw ReplSafeModeException.
 */
#[Internal]
final readonly class ReadOnlyStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private StorageAdapterInterface $inner,
    ) {}

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        throw ReplSafeModeException::operationBlocked('storage:put');
    }

    #[Override]
    public function get(string $key): string
    {
        return $this->inner->get($key);
    }

    #[Override]
    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function delete(string $key): void
    {
        throw ReplSafeModeException::operationBlocked('storage:delete');
    }

    /**
     * @return list<StorageObject>
     */
    #[Override]
    public function list(string $prefix = ''): array
    {
        return $this->inner->list($prefix);
    }

    #[Override]
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        return $this->inner->temporaryUrl($key, $expiresInSeconds);
    }
}
