<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Security\Session\SessionInterface;

#[CoversClass(SessionGuard::class)]
final class SessionGuardTest extends TestCase
{
    /** @var SessionInterface&MockObject */
    private SessionInterface $session;
    private SessionGuard $guard;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $this->guard = new SessionGuard($this->session);
    }

    #[Test]
    public function nameReturnsSession(): void
    {
        self::assertSame('session', $this->guard->name());
    }

    #[Test]
    public function authenticateReturnsNullWhenSessionNotStarted(): void
    {
        $this->session->method('isStarted')->willReturn(false);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        self::assertNull($this->guard->authenticate($request));
    }

    #[Test]
    public function authenticateReturnsNullWhenNoIdentityInSession(): void
    {
        $this->session->method('isStarted')->willReturn(true);
        $this->session->method('has')->with('_pulsar_identity')->willReturn(false);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        self::assertNull($this->guard->authenticate($request));
    }

    #[Test]
    public function authenticateReturnsIdentityWhenSessionContainsSerializedData(): void
    {
        $identityData = [
            'id' => 'user-1',
            'display_name' => 'Test User',
            'roles' => ['admin'],
            'two_factor_status' => 'disabled',
            'attributes' => [],
        ];

        $this->session->method('isStarted')->willReturn(true);
        $this->session->method('has')->with('_pulsar_identity')->willReturn(true);
        $this->session->method('get')->with('_pulsar_identity')->willReturn($identityData);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $identity = $this->guard->authenticate($request);

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('user-1', $identity->id());
        self::assertSame('Test User', $identity->displayName());
        self::assertSame(['admin'], $identity->roles());
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
        self::assertSame([], $identity->attributes());
    }

    #[Test]
    public function loginRegeneratesSessionAndStoresIdentity(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $this->session->expects(self::once())->method('regenerate');
        $this->session->expects(self::once())->method('set')->with(
            '_pulsar_identity',
            $identity->toArray(),
        );

        $this->guard->login($identity);
    }

    #[Test]
    public function logoutRemovesIdentityAndRegenerates(): void
    {
        $this->session->expects(self::once())->method('remove')->with('_pulsar_identity');
        $this->session->expects(self::once())->method('regenerate');

        $this->guard->logout();
    }

    #[Test]
    public function updateIdentityStoresIdentityWithoutRegenerating(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Verified,
            attributes: [],
        );

        $this->session->expects(self::never())->method('regenerate');
        $this->session->expects(self::once())->method('set')->with(
            '_pulsar_identity',
            $identity->toArray(),
        );

        $this->guard->updateIdentity($identity);
    }
}
