<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Themes\PreviewSession;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;

/**
 * Array-backed preview session store for environments without Redis/Memcached.
 *
 * Used as a fallback when TaggedCacheInterface is not available. Preview
 * sessions are stored in-process memory and do not survive across requests,
 * which is acceptable for development and single-process deployments.
 *
 * @psalm-api Fallback binding for PreviewSessionRepositoryInterface in the CMS
 *            service provider; resolved from the DI container, never instantiated
 *            by name.
 */
#[Internal(reason: 'Fallback implementation when no tagged cache is available')]
final class InMemoryPreviewSessionRepository implements PreviewSessionRepositoryInterface
{
    /** @var array<string, PreviewSession> */
    private array $sessions = [];

    #[Override]
    public function save(PreviewSession $session): void
    {
        $this->sessions[$session->token] = $session;
    }

    #[Override]
    public function findByToken(string $token): ?PreviewSession
    {
        $session = $this->sessions[$token] ?? null;

        if ($session === null) {
            return null;
        }

        if ($session->isExpired()) {
            unset($this->sessions[$token]);

            return null;
        }

        return $session;
    }

    #[Override]
    public function deleteExpired(): int
    {
        $deleted = 0;

        foreach ($this->sessions as $token => $session) {
            if ($session->isExpired()) {
                unset($this->sessions[$token]);
                $deleted++;
            }
        }

        return $deleted;
    }
}
