<?php

declare(strict_types=1);

namespace Pulsar\Documentation;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Configuration for versioned documentation resolution.
 *
 * Opt-in (disabled by default): when enabled with at least one version, the
 * {@see DocVersionResolverMiddleware} is wired globally so requests under
 * /docs/{version}/… carry the resolved {@see DocVersion}. The version list is
 * application-specific, so it is supplied by the operator.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DocumentationConfig
{
    /**
     * @param list<DocVersion> $versions
     */
    public function __construct(
        public bool $enabled = false,
        public array $versions = [],
    ) {}

    /**
     * Usable only when enabled with at least one version (the registry's
     * latest() has no answer for an empty set).
     */
    #[NoDiscard]
    public function isUsable(): bool
    {
        return $this->enabled && $this->versions !== [];
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     versions?: list<array<string, mixed>>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawVersions = $data['versions'] ?? null;
        $versions = [];

        if (is_array($rawVersions)) {
            foreach ($rawVersions as $entry) {
                $version = self::buildVersion($entry);
                if ($version !== null) {
                    $versions[] = $version;
                }
            }
        }

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            versions: $versions,
        );
    }

    private static function buildVersion(mixed $entry): ?DocVersion
    {
        if (!is_array($entry)) {
            return null;
        }

        $version = Coerce::string($entry['version'] ?? null);
        if ($version === '') {
            return null;
        }

        return new DocVersion(
            version: $version,
            label: Coerce::string($entry['label'] ?? null, $version),
            basePath: Coerce::string($entry['base_path'] ?? null, 'docs/' . $version),
            isLatest: Coerce::strictBool($entry['is_latest'] ?? null),
            isPrerelease: Coerce::strictBool($entry['is_prerelease'] ?? null),
        );
    }
}
