<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Persistence contract for theme preview sessions.
 */
#[Api(since: '1.0.0')]
interface PreviewSessionRepositoryInterface
{
    public function save(PreviewSession $session): void;

    public function findByToken(string $token): ?PreviewSession;

    public function deleteExpired(): int;
}
