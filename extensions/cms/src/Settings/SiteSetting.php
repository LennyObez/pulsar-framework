<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Settings;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A typed site setting with per-tenant and per-locale support.
 *
 * Settings are organized by group (e.g., general, seo, comments, media)
 * and store JSON-encoded typed values.
 *
 * @psalm-api Public DTO returned from SettingsServiceInterface; consumed by
 *            user-land code and admin templates.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SiteSetting
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $group Setting group (e.g., general, seo, comments, media, security)
     * @param string $key Setting key, unique per (tenant_id, group, locale)
     * @param string|null $locale BCP 47 locale code, null = global setting
     * @param string $value JSON-encoded typed value
     * @param string $valueType Type hint (string, int, bool, float, json, encrypted)
     * @param DateTimeImmutable $updatedAt Last modification timestamp
     * @param string $updatedBy UUIDv7 user who last modified the setting
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $group,
        public string $key,
        public ?string $locale,
        public string $value,
        public string $valueType,
        public DateTimeImmutable $updatedAt,
        public string $updatedBy,
    ) {}
}
