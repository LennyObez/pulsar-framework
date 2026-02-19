<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Persistence interface for URL redirects.
 */
#[Api(since: '1.0.0')]
interface RedirectRepositoryInterface
{
    /**
     * Find a redirect matching the given path, optionally scoped by locale and tenant.
     */
    public function findByPath(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect;

    /**
     * Persist a redirect record.
     */
    public function save(Redirect $redirect): void;

    /**
     * Increment the hit counter and update lastHitAt for a redirect.
     */
    public function incrementHits(string $redirectId): void;
}
