<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Value object representing the result of a single URL health check.
 */
#[Api(since: '1.0.0')]
final readonly class LinkHealthCheck
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $sourceContentId UUIDv7 of the content containing the link
     * @param string $sourceLocale BCP 47 locale of the source content
     * @param string $targetUrl The URL being checked
     * @param bool $isBroken Whether the link is broken
     * @param bool $isRedirected Whether the link redirects
     * @param int|null $httpStatusCode HTTP response status code, null if unreachable
     * @param DateTimeImmutable $lastCheckedAt When the check was last performed
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $sourceContentId,
        public string $sourceLocale,
        public string $targetUrl,
        public bool $isBroken,
        public bool $isRedirected,
        public ?int $httpStatusCode,
        public DateTimeImmutable $lastCheckedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
