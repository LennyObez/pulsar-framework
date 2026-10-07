<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Concern;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Testing\Concern\InteractsWithAuthentication;
use Pulsar\Testing\TestCase;

final class InteractsWithAuthenticationTest extends TestCase
{
    use InteractsWithAuthentication;

    #[Test]
    public function actingAsSetsIdentity(): void
    {
        $identity = new Identity('user-1', 'Alice', ['admin']);

        $result = $this->actingAs($identity);

        self::assertSame($identity, $this->currentIdentity());
        self::assertSame($this, $result, 'actingAs() should return $this for chaining');
    }

    #[Test]
    public function currentIdentityIsNullByDefault(): void
    {
        self::assertNull($this->currentIdentity());
    }

    #[Test]
    public function assertAuthenticatedPassesWhenIdentitySet(): void
    {
        $this->actingAs(new Identity('user-1', 'Alice'));

        $this->assertAuthenticated();
    }

    #[Test]
    public function assertAuthenticatedWithIdPassesWhenMatching(): void
    {
        $this->actingAs(new Identity('user-42', 'Bob'));

        $this->assertAuthenticated('user-42');
    }

    #[Test]
    public function assertAuthenticatedFailsWhenNoIdentity(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertAuthenticated();
    }

    #[Test]
    public function assertAuthenticatedFailsForAnonymousIdentity(): void
    {
        $this->actingAs(new AnonymousIdentity());

        $this->expectException(AssertionFailedError::class);

        $this->assertAuthenticated();
    }

    #[Test]
    public function assertGuestPassesWhenNoIdentity(): void
    {
        $this->assertGuest();
    }

    #[Test]
    public function assertGuestPassesForAnonymousIdentity(): void
    {
        $this->actingAs(new AnonymousIdentity());

        $this->assertGuest();
    }

    #[Test]
    public function assertGuestFailsWhenAuthenticated(): void
    {
        $this->actingAs(new Identity('user-1', 'Alice'));

        $this->expectException(AssertionFailedError::class);

        $this->assertGuest();
    }

    #[Test]
    public function resetAuthenticationClearsIdentity(): void
    {
        $this->actingAs(new Identity('user-1', 'Alice'));
        $this->resetAuthentication();

        self::assertNull($this->currentIdentity());
    }

    #[Test]
    public function actingAsCanBeCalledMultipleTimes(): void
    {
        $alice = new Identity('user-1', 'Alice');
        $bob = new Identity('user-2', 'Bob');

        $this->actingAs($alice);
        $current = $this->currentIdentity();
        self::assertNotNull($current);
        self::assertSame('user-1', $current->id());

        $this->actingAs($bob);
        $current = $this->currentIdentity();
        self::assertNotNull($current);
        self::assertSame('user-2', $current->id());
    }
}
