<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Storage\StorageAdapterInterface;

/**
 * Factory interface for cloud provider service creation.
 *
 * Each cloud provider (AWS, GCP, Azure) implements this to expose
 * provider-specific service adapters through a uniform factory API.
 */
#[Api(since: '1.0.0')]
interface CloudProviderInterface
{
    /**
     * Get the provider name (e.g. "aws", "gcp", "azure").
     */
    #[NoDiscard]
    public function name(): string;

    /**
     * Create a storage adapter for this provider.
     *
     * @param array<string, mixed> $config Adapter-specific configuration
     */
    #[NoDiscard]
    public function createStorageAdapter(array $config = []): StorageAdapterInterface;

    /**
     * Check if this provider is configured and available.
     */
    #[NoDiscard]
    public function isAvailable(): bool;
}
