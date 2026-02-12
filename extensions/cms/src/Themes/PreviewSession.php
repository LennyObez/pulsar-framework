<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Represents an active theme preview session bound to a specific user.
 */
#[Api(since: '1.0.0')]
final readonly class PreviewSession
{
    public function __construct(
        public string $themeId,
        public string $token,
        public string $userId,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return new DateTimeImmutable() >= $this->expiresAt;
    }
}
