<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageMetadata;
use Pulsar\Storage\StorageObject;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function sprintf;
use function str_starts_with;
use function strlen;
use function time;

/**
 * Fake storage adapter for testing: in-memory file store with operation tracking.
 *
 * Records all put/get/delete operations and provides assertions for
 * verifying storage behavior without a real filesystem or object store.
 */
#[Api(since: '1.0.0')]
final class StorageFake implements StorageAdapterInterface
{
    /** @var array<string, string> key => content */
    private array $files = [];

    /** @var array<string, StorageMetadata> key => metadata */
    private array $metadata = [];

    /** @var list<array{operation: string, key: string}> */
    private array $operations = [];

    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        $this->operations[] = ['operation' => 'put', 'key' => $key];
        $this->files[$key] = $content;

        if ($metadata !== null) {
            $this->metadata[$key] = $metadata;
        }
    }

    public function get(string $key): string
    {
        $this->operations[] = ['operation' => 'get', 'key' => $key];

        if (!array_key_exists($key, $this->files)) {
            throw StorageException::objectNotFound($key);
        }

        return $this->files[$key];
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->files);
    }

    public function delete(string $key): void
    {
        $this->operations[] = ['operation' => 'delete', 'key' => $key];

        if (!array_key_exists($key, $this->files)) {
            throw StorageException::objectNotFound($key);
        }

        unset($this->files[$key], $this->metadata[$key]);
    }

    /** @return list<StorageObject> */
    public function list(string $prefix = ''): array
    {
        $objects = [];

        foreach ($this->files as $key => $content) {
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                $objects[] = new StorageObject(
                    key: $key,
                    size: strlen($content),
                    lastModified: time(),
                );
            }
        }

        return $objects;
    }

    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        if (!$this->exists($key)) {
            return null;
        }

        return sprintf('https://fake-storage.test/%s?expires=%d', $key, $expiresInSeconds);
    }

    /**
     * Assert that a file exists in storage.
     */
    public function assertExists(string $key): void
    {
        Assert::assertTrue(
            $this->exists($key),
            sprintf(
                "Expected file [%s] to exist in storage, but it does not.\nFiles in storage: %s",
                $key,
                $this->formatKeys(),
            ),
        );
    }

    /**
     * Assert that a file does NOT exist in storage.
     */
    public function assertMissing(string $key): void
    {
        Assert::assertFalse(
            $this->exists($key),
            sprintf(
                'Expected file [%s] NOT to exist in storage, but it does.',
                $key,
            ),
        );
    }

    /**
     * Assert that a file has specific content.
     */
    public function assertContent(string $key, string $expectedContent): void
    {
        $this->assertExists($key);

        Assert::assertSame(
            $expectedContent,
            $this->files[$key],
            sprintf(
                "Expected file [%s] to have specific content, but it differs.\nExpected length: %d\nActual length: %d",
                $key,
                strlen($expectedContent),
                strlen($this->files[$key]),
            ),
        );
    }

    /**
     * Assert the exact number of files in storage (optionally under a prefix).
     */
    public function assertCount(int $expected, string $prefix = ''): void
    {
        $matching = $this->list($prefix);

        Assert::assertCount(
            $expected,
            $matching,
            sprintf(
                "Expected %d file(s)%s, but found %d.\nFiles: %s",
                $expected,
                $prefix !== '' ? sprintf(' under prefix [%s]', $prefix) : '',
                count($matching),
                implode(', ', array_map(
                    static fn(StorageObject $o): string => $o->key,
                    $matching,
                )),
            ),
        );
    }

    /**
     * Assert a storage operation was performed.
     *
     * @param string $operation One of: put, get, delete
     */
    public function assertOperation(string $operation, ?string $key = null): void
    {
        $matching = array_filter(
            $this->operations,
            static fn(array $op): bool => $op['operation'] === $operation
                && ($key === null || $op['key'] === $key),
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected storage operation [%s]%s, but it did not occur.\nRecorded operations: %s",
                $operation,
                $key !== null ? sprintf(' on key [%s]', $key) : '',
                $this->formatOperations(),
            ),
        );
    }

    /**
     * Get metadata for a stored file, or null if not set.
     */
    public function getMetadata(string $key): ?StorageMetadata
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * Assert storage is empty.
     */
    public function assertEmpty(): void
    {
        Assert::assertEmpty(
            $this->files,
            sprintf(
                'Expected storage to be empty, but it contains %d file(s): %s',
                count($this->files),
                $this->formatKeys(),
            ),
        );
    }

    /**
     * Get all recorded operations.
     *
     * @return list<array{operation: string, key: string}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Get all stored files.
     *
     * @return array<string, string>
     */
    public function allFiles(): array
    {
        return $this->files;
    }

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->files = [];
        $this->metadata = [];
        $this->operations = [];
    }

    private function formatKeys(): string
    {
        $keys = array_keys($this->files);

        return $keys === [] ? '(empty)' : implode(', ', $keys);
    }

    private function formatOperations(): string
    {
        if ($this->operations === []) {
            return '(none)';
        }

        $parts = [];

        foreach ($this->operations as $op) {
            $parts[] = sprintf('%s(%s)', $op['operation'], $op['key']);
        }

        return implode(', ', $parts);
    }
}
