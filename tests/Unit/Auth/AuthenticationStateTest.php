<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth;

use Fiber;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\AuthenticationState;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Runtime\ResettableInterface;
use RuntimeException;

/**
 * The channel the framework's own authentication publishes its answer on, and
 * the one the model binding layer authorizes against.
 *
 * It exists because the previous channel was a PSR-7 request attribute, which
 * every frame in the pipeline can write — so "who is calling" was decided by
 * whoever wrote last. The properties below are what makes this one different,
 * and each of them is load-bearing rather than incidental.
 */
#[CoversClass(AuthenticationState::class)]
final class AuthenticationStateTest extends TestCase
{
    #[Test]
    public function nothingIsEstablishedUntilAuthenticationRuns(): void
    {
        $state = new AuthenticationState();

        self::assertNull($state->context());
        self::assertNull($state->authenticatedIdentity());
    }

    #[Test]
    public function anAnonymousCallerIsEstablishedAndStillNamesNobody(): void
    {
        // "Not established" and "anonymous" are different answers, and reading
        // one as the other is how a request with nobody behind it becomes a
        // request with an identity present.
        $state = new AuthenticationState();
        $state->establish($this->lazyContext(new AnonymousIdentity()));

        self::assertInstanceOf(SecurityContext::class, $state->context());
        self::assertNull($state->authenticatedIdentity());
    }

    #[Test]
    public function theCallerIsResolvedThroughTheContextsMemoAndNotPerAsk(): void
    {
        // The binding layer asks once per bound route; the `auth` alias asks
        // through the same context. Reaching past the memo to the AuthManager
        // would be a second session read or token verification on every
        // `auth`-guarded bound route.
        $manager = new CountingAuthManager(new Identity('user-9', 'Nine'));
        $state = new AuthenticationState();
        $state->establish(new SecurityContext($manager, new ServerRequest(method: 'GET', uri: '/')));

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame('user-9', $state->authenticatedIdentity()?->id());
        }

        self::assertSame(1, $manager->calls, 'The guards must run once per request, not once per question.');
    }

    #[Test]
    public function aPreResolvedContextConsultsNoGuardAtAll(): void
    {
        // `pulsar serve --dev-identity`: the composition root knows the caller
        // before any credential exists, so the context is seeded rather than
        // resolved.
        $manager = new CountingAuthManager(new AnonymousIdentity());
        $devIdentity = new Identity('dev-admin', 'Dev Administrator');

        $state = new AuthenticationState();
        $state->establish(SecurityContext::established(
            $manager,
            new ServerRequest(method: 'GET', uri: '/'),
            $devIdentity,
        ));

        self::assertSame($devIdentity, $state->authenticatedIdentity());
        self::assertSame(0, $manager->calls);
    }

    #[Test]
    public function theLastPublicationWins(): void
    {
        $state = new AuthenticationState();
        $state->establish($this->lazyContext(new Identity('first', 'First')));

        $second = $this->lazyContext(new Identity('second', 'Second'));
        $state->establish($second);

        self::assertSame($second, $state->context());
        self::assertSame('second', $state->authenticatedIdentity()?->id());
    }

    #[Test]
    public function resettingDropsThePublication(): void
    {
        // A resident worker reuses one key for every request it serves, so a
        // publication left behind is the NEXT caller's identity. RuntimeWiring
        // registers this holder for exactly this reason, and the registration
        // is not optional.
        $state = new AuthenticationState();
        $state->establish($this->lazyContext(new Identity('first-caller', 'First')));

        self::assertInstanceOf(ResettableInterface::class, $state);

        $state->resetRequestState();

        self::assertNull($state->context());
        self::assertNull($state->authenticatedIdentity());
    }

    #[Test]
    public function oneFibersCallerIsNotAnothers(): void
    {
        // ADR-0010 guarantees that individual request handling is sequential
        // today, so every request lands on the root key and the Fiber key costs
        // nothing. It is here so that relaxing that guarantee does not silently
        // turn this holder into a cross-request identity leak — the failure
        // mode a shared field would have.
        $state = new AuthenticationState();
        $state->establish($this->lazyContext(new Identity('root-caller', 'Root')));

        $seenInsideFiber = 'unset';

        $fiber = new Fiber(function () use ($state, &$seenInsideFiber): void {
            $seenInsideFiber = $state->authenticatedIdentity()?->id();

            $state->establish($this->lazyContext(new Identity('fiber-caller', 'Fiber')));

            Fiber::suspend();

            $seenInsideFiber = $state->authenticatedIdentity()?->id();
        });

        $fiber->start();

        self::assertNull($seenInsideFiber, 'A Fiber must not inherit the root caller.');
        self::assertSame(
            'root-caller',
            $state->authenticatedIdentity()?->id(),
            'A Fiber publishing its own caller must not overwrite the root one.',
        );

        $fiber->resume();

        self::assertSame('fiber-caller', $seenInsideFiber);
    }

    private function lazyContext(IdentityInterface $identity): SecurityContext
    {
        return new SecurityContext(new CountingAuthManager($identity), new ServerRequest(method: 'GET', uri: '/'));
    }
}

final class CountingAuthManager implements AuthManagerInterface
{
    public int $calls = 0;

    public function __construct(private readonly IdentityInterface $identity) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): IdentityInterface
    {
        ++$this->calls;

        return $this->identity;
    }

    #[Override]
    public function guard(string $name): GuardInterface
    {
        throw new RuntimeException('Nothing in this path may reach for a named guard.');
    }

    #[Override]
    public function defaultGuard(): string
    {
        return 'counting';
    }
}
