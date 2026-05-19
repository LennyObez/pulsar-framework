<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\TrustTier;

use function array_filter;
use function array_values;
use function count;
use function end;
use function explode;

/**
 * Represents an extension listing in the marketplace.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExtensionListing
{
    /**
     * @param string $name Vendor-qualified name (e.g., "pulsar/analytics")
     * @param string $version Current version
     * @param string $author Author name
     * @param string $description Short description
     * @param TrustTier $trustTier Effective trust tier
     * @param int $downloads Total download count
     * @param float $rating Average rating (0.0–5.0)
     * @param string $pulsarMinVersion Minimum compatible Pulsar version
     * @param string $pulsarMaxVersion Maximum compatible Pulsar version (empty = no upper bound)
     * @param list<string> $categories Extension categories
     * @param string $homepage Project homepage URL
     * @param string $license SPDX license identifier
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $author = '',
        public string $description = '',
        public TrustTier $trustTier = TrustTier::Community,
        public int $downloads = 0,
        public float $rating = 0.0,
        public string $pulsarMinVersion = '0.0.0',
        public string $pulsarMaxVersion = '',
        public array $categories = [],
        public string $homepage = '',
        public string $license = '',
    ) {}

    /**
     * @param array{
     *     name?: string,
     *     version?: string,
     *     author?: string,
     *     description?: string,
     *     trust_tier?: string,
     *     downloads?: int,
     *     rating?: float|int,
     *     pulsar_min_version?: string,
     *     pulsar_max_version?: string,
     *     categories?: list<string>,
     *     homepage?: string,
     *     license?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $categories = array_values(array_filter($data['categories'] ?? [], 'is_string'));

        return new self(
            name: $data['name'] ?? '',
            version: $data['version'] ?? '0.0.0',
            author: $data['author'] ?? '',
            description: $data['description'] ?? '',
            trustTier: TrustTier::tryFrom($data['trust_tier'] ?? '') ?? TrustTier::Community,
            downloads: $data['downloads'] ?? 0,
            rating: (float) ($data['rating'] ?? 0.0),
            pulsarMinVersion: $data['pulsar_min_version'] ?? '0.0.0',
            pulsarMaxVersion: $data['pulsar_max_version'] ?? '',
            categories: $categories,
            homepage: $data['homepage'] ?? '',
            license: $data['license'] ?? '',
        );
    }

    /**
     * Get the short name (without vendor prefix).
     */
    public function shortName(): string
    {
        $parts = explode('/', $this->name);

        return end($parts) ?: $this->name;
    }

    /**
     * Get the vendor name.
     */
    public function vendor(): string
    {
        $parts = explode('/', $this->name);

        return count($parts) > 1 ? $parts[0] : '';
    }

    /**
     * Whether this extension is official (core trust tier).
     */
    public function isOfficial(): bool
    {
        return $this->trustTier === TrustTier::Core;
    }

    /**
     * Whether this extension is verified.
     */
    public function isVerified(): bool
    {
        return $this->trustTier->atLeast(TrustTier::Verified);
    }
}
