<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * CSS override entity: represents a versioned set of custom CSS and token
 * overrides applied on top of an installed theme.
 */
#[Api(since: '1.0.0')]
final readonly class CssOverride
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $themeId UUIDv7 of the target theme
     * @param int $version Monotonically increasing version number
     * @param string $cssContent Validated custom CSS
     * @param string $cssHash SHA-256 hash of cssContent for CSP
     * @param array<string, string> $tokenOverrides Token name => value overrides
     * @param bool $isActive Whether this override is the currently active version
     * @param DateTimeImmutable $createdAt Creation timestamp
     * @param string $createdBy UUIDv7 of the actor who created this override
     * @param string $reason Human-readable reason for the change
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $themeId,
        public int $version,
        public string $cssContent,
        public string $cssHash,
        public array $tokenOverrides,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public string $createdBy,
        public string $reason,
    ) {}

    /**
     * Create a new active CSS override.
     *
     * @param array<string, string> $tokenOverrides
     */
    public static function create(
        string $id,
        string $themeId,
        int $version,
        string $cssContent,
        string $cssHash,
        array $tokenOverrides,
        string $createdBy,
        string $reason,
        ?string $tenantId = null,
    ): self {
        return new self(
            id: $id,
            tenantId: $tenantId,
            themeId: $themeId,
            version: $version,
            cssContent: $cssContent,
            cssHash: $cssHash,
            tokenOverrides: $tokenOverrides,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            createdBy: $createdBy,
            reason: $reason,
        );
    }
}
