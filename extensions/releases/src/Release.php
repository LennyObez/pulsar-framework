<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Immutable entity representing an application release.
 *
 * Tracks version history, platform targeting, release notes, and
 * beta/stable lifecycle transitions via clone-with semantics.
 */
#[Api(since: '1.0.0')]
final readonly class Release
{
    /**
     * @param string $id Hex-encoded random identifier (32 chars)
     * @param string $version Semantic version string (e.g. "2.1.0")
     * @param ReleasePlatform $platform Target platform for this release
     * @param DateTimeImmutable $releaseDate Planned or actual release date
     * @param string $releaseNotes Markdown-formatted release notes
     * @param string $minimumOsVersion Minimum OS version required
     * @param string|null $downloadUrl URL to download the release binary
     * @param bool $isBeta Whether this is a beta/pre-release
     * @param bool $isStable Whether this release has been marked stable
     * @param DateTimeImmutable $createdAt Record creation timestamp
     */
    public function __construct(
        public string $id,
        public string $version,
        public ReleasePlatform $platform,
        public DateTimeImmutable $releaseDate,
        public string $releaseNotes,
        public string $minimumOsVersion,
        public ?string $downloadUrl,
        public bool $isBeta,
        public bool $isStable,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new release with a generated identifier and current timestamp.
     */
    public static function create(
        string $version,
        ReleasePlatform $platform,
        DateTimeImmutable $releaseDate,
        string $releaseNotes,
        string $minimumOsVersion,
        ?string $downloadUrl = null,
        bool $isBeta = false,
        bool $isStable = false,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            version: $version,
            platform: $platform,
            releaseDate: $releaseDate,
            releaseNotes: $releaseNotes,
            minimumOsVersion: $minimumOsVersion,
            downloadUrl: $downloadUrl,
            isBeta: $isBeta,
            isStable: $isStable,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Mark this release as stable.
     */
    #[NoDiscard]
    public function markStable(): static
    {
        return clone($this, [
            'isStable' => true,
        ]);
    }
}
