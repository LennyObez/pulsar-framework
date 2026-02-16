<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Pulsar\Api\Api;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Minimal auth helper injected as `$__auth` into compiled templates.
 *
 * Provides `can()`, `guest()`, and `authenticated()` checks used by
 * the @can, @auth, and @guest directives.
 */
#[Api(since: '1.0.0')]
final readonly class TemplateAuthHelper
{
    public function __construct(
        private ?GateInterface $gate,
        private ?IdentityInterface $identity,
    ) {}

    /**
     * Check if the current identity has the given ability.
     */
    public function can(string $ability): bool
    {
        if ($this->gate === null || $this->identity === null) {
            return false;
        }

        return $this->gate->allows($this->identity, $ability);
    }

    /**
     * Check if there is an authenticated identity.
     */
    public function authenticated(): bool
    {
        return $this->identity !== null && $this->identity->isAuthenticated();
    }

    /**
     * Check if the current user is a guest (not authenticated).
     */
    public function guest(): bool
    {
        return !$this->authenticated();
    }
}
