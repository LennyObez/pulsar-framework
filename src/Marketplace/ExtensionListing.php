<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\TrustTier;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $trustTierValue = is_string($data['trust_tier'] ?? null) ? $data['trust_tier'] : '';

        /** @var list<string> $categories */
        $categories = isset($data['categories']) && is_array($data['categories'])
            ? array_values(array_filter($data['categories'], 'is_string'))
            : [];

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            version: is_string($data['version'] ?? null) ? $data['version'] : '0.0.0',
            author: is_string($data['author'] ?? null) ? $data['author'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            trustTier: TrustTier::tryFrom($trustTierValue) ?? TrustTier::Community,
            downloads: is_int($data['downloads'] ?? null) ? $data['downloads'] : 0,
            rating: is_float($data['rating'] ?? null) || is_int($data['rating'] ?? null)
                ? (float) $data['rating'] : 0.0,
            pulsarMinVersion: is_string($data['pulsar_min_version'] ?? null)
                ? $data['pulsar_min_version'] : '0.0.0',
            pulsarMaxVersion: is_string($data['pulsar_max_version'] ?? null)
                ? $data['pulsar_max_version'] : '',
            categories: $categories,
            homepage: is_string($data['homepage'] ?? null) ? $data['homepage'] : '',
            license: is_string($data['license'] ?? null) ? $data['license'] : '',
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
