<?php

declare(strict_types=1);

namespace Pulsar\Testing\Concern;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Provides actingAs() and authentication assertions for test cases.
 *
 * Sets the authenticated identity for the test scope, enabling
 * downstream code to resolve the identity via the test-scoped guard.
 *
 * Usage:
 *   $this->actingAs($identity);
 *   self::assertAuthenticated();
 */
#[Api(since: '1.0.0')]
trait InteractsWithAuthentication
{
    private ?IdentityInterface $authenticatedIdentity = null;

    /**
     * Set the authenticated identity for subsequent operations in this test.
     *
     * @param IdentityInterface $identity The identity to authenticate as
     *
     * @return static
     */
    protected function actingAs(IdentityInterface $identity): static
    {
        $this->authenticatedIdentity = $identity;

        return $this;
    }

    /**
     * Get the identity set by actingAs(), or null if acting as guest.
     */
    protected function currentIdentity(): ?IdentityInterface
    {
        return $this->authenticatedIdentity;
    }

    /**
     * Assert that the test is acting as an authenticated identity.
     */
    protected function assertAuthenticated(?string $expectedId = null): void
    {
        $identity = $this->authenticatedIdentity;

        self::assertNotNull(
            $identity,
            'Expected an authenticated identity, but none was set via actingAs().',
        );

        self::assertTrue(
            $identity->isAuthenticated(),
            'The current identity is not authenticated.',
        );

        if ($expectedId !== null) {
            self::assertSame(
                $expectedId,
                $identity->id(),
                "Expected authenticated identity '$expectedId', got '{$identity->id()}'.",
            );
        }
    }

    /**
     * Assert that the test is acting as a guest (no authenticated identity).
     */
    protected function assertGuest(): void
    {
        if ($this->authenticatedIdentity === null) {
            // No identity set — acting as guest. Count an assertion to avoid risky test.
            $this->addToAssertionCount(1);

            return;
        }

        self::assertFalse(
            $this->authenticatedIdentity->isAuthenticated(),
            'Expected guest (unauthenticated), but an authenticated identity is set.',
        );
    }

    /**
     * Reset the authenticated identity. Called automatically in tearDown.
     */
    protected function resetAuthentication(): void
    {
        $this->authenticatedIdentity = null;
    }
}
