<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * URL redirect record for SEO-safe path changes.
 *
 * Created automatically when content slugs or hierarchy change,
 * and can also be created manually by administrators.
 */
#[Api(since: '1.0.0')]
final readonly class Redirect
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $fromPath Source path (relative)
     * @param string $toPath Destination path or full URL
     * @param int $statusCode HTTP redirect status code (301 or 308)
     * @param string|null $locale BCP 47 locale code, null = all locales
     * @param int $hits Number of times this redirect has been followed
     * @param DateTimeImmutable|null $lastHitAt When the redirect was last followed
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param string $createdBy UUIDv7 user who created the redirect
     * @param string $reason Mandatory reason for the redirect
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $fromPath,
        public string $toPath,
        public int $statusCode,
        public ?string $locale,
        public int $hits,
        public ?DateTimeImmutable $lastHitAt,
        public DateTimeImmutable $createdAt,
        public string $createdBy,
        public string $reason,
    ) {}
}
