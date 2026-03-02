<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_diff;
use function array_keys;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;

use const JSON_ERROR_NONE;

/**
 * Parsed site definition for full-site import.
 *
 * Represents the validated structure of a site definition JSON document
 * conforming to the N.3 import schema.
 */
#[Api(since: '1.0.0')]
final readonly class SiteDefinition
{
    private const array REQUIRED_KEYS = ['version', 'site'];

    /**
     * @param array<string, mixed> $site Site-level configuration (name, locales, settings)
     * @param list<array<string, mixed>> $taxonomies Taxonomy definitions with terms
     * @param list<array<string, mixed>> $content Content items with translations and blocks
     * @param list<array<string, mixed>> $menus Menu definitions with items
     * @param list<array<string, mixed>> $media Media asset references with source URLs
     * @param list<array<string, mixed>> $redirects URL redirect definitions
     * @param array<string, mixed> $seo SEO configuration (robots, sitemap, structured data)
     * @param array<string, mixed>|null $forum Optional forum section for cross-extension import
     */
    public function __construct(
        public array $site,
        public array $taxonomies,
        public array $content,
        public array $menus,
        public array $media,
        public array $redirects,
        public array $seo,
        public ?array $forum = null,
    ) {}

    /**
     * Parse and validate a JSON string into a SiteDefinition.
     *
     * @throws InvalidArgumentException If the JSON is malformed or missing required keys
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        $missingKeys = array_diff(self::REQUIRED_KEYS, array_keys($data));

        if ($missingKeys !== []) {
            throw new InvalidArgumentException(
                'Missing required keys in site definition: ' . implode(', ', $missingKeys),
            );
        }

        if (!is_array($data['site'])) {
            throw new InvalidArgumentException('The "site" key must be an object');
        }

        $version = $data['version'] ?? null;

        if ($version !== '1.0') {
            $versionStr = is_string($version) ? $version : (is_scalar($version) ? (string) $version : 'unknown');

            throw new InvalidArgumentException(
                "Unsupported site definition version: $versionStr. Expected: 1.0",
            );
        }

        /** @var array<string, mixed> $siteData */
        $siteData = $data['site'];
        $seoValue = $data['seo'] ?? [];
        /** @var array<string, mixed> $seoData */
        $seoData = is_array($seoValue) ? $seoValue : [];

        $forumValue = $data['forum'] ?? null;
        /** @var array<string, mixed>|null $forumData */
        $forumData = is_array($forumValue) ? $forumValue : null;

        return new self(
            site: $siteData,
            taxonomies: self::ensureList($data, 'taxonomies'),
            content: self::ensureList($data, 'content'),
            menus: self::ensureList($data, 'menus'),
            media: self::ensureList($data, 'media'),
            redirects: self::ensureList($data, 'redirects'),
            seo: $seoData,
            forum: $forumData,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function ensureList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value)) {
            throw new InvalidArgumentException("The \"$key\" key must be an array");
        }

        $result = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = $item;
            }
        }

        return $result;
    }
}
