<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Guard;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Security\Session\SessionManager;

#[CoversClass(SessionGuard::class)]
final class SessionGuardTest extends TestCase
{
    /** @var SessionInterface&Stub */
    private SessionInterface $session;
    private SessionGuard $guard;

    protected function setUp(): void
    {
        $this->session = $this->createStub(SessionInterface::class);
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );

        self::assertNull($this->guard->authenticate($request));
    }

    #[Test]
    public function authenticateReturnsNullWhenNoIdentityInSession(): void
    {
        $this->session->method('isStarted')->willReturn(true);
        $this->session->method('has')->willReturn(false);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
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
        $this->session->method('has')->willReturn(true);
        $this->session->method('get')->willReturn($identityData);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
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

        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('regenerate');
        $session->expects(self::once())->method('set')->with(
            '_pulsar_identity',
            $identity->toArray(),
        );

        $guard = new SessionGuard($session);
        $guard->login($identity);
    }

    #[Test]
    public function logoutRemovesIdentityAndRegenerates(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('remove')->with('_pulsar_identity');
        $session->expects(self::once())->method('regenerate');

        $guard = new SessionGuard($session);
        $guard->logout();
    }

    #[Test]
    public function loginWritesAnIdentityKeyRecognisedByTheSessionAuthGate(): void
    {
        // Desync guard: the PCI-DSS 8.2.8 idle gate classifies a session as
        // authenticated from SessionConfig::authenticatedMarkerKeys. Prove that a
        // real guard login writes a session-data key those markers recognise, so the
        // gate (SessionManager) and the guard cannot silently drift apart.
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );
        $session = new SessionManager(new ArrayHandler(), $config);
        $session->start();

        $identity = new Identity(
            id: 'user-42',
            displayName: 'Auth User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        new SessionGuard($session)->login($identity);

        $recognised = false;
        foreach ($config->authenticatedMarkerKeys as $key) {
            if ($session->get($key) !== null) {
                $recognised = true;
                break;
            }
        }

        self::assertTrue(
            $recognised,
            'After a guard login the session must carry a data key that SessionConfig recognises as authenticated (PCI-DSS 8.2.8 gate source of truth).',
        );
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

        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method('regenerate');
        $session->expects(self::once())->method('set')->with(
            '_pulsar_identity',
            $identity->toArray(),
        );

        $guard = new SessionGuard($session);
        $guard->updateIdentity($identity);
    }

    #[Test]
    public function authenticateReturnsNullWhenSessionDataIsNull(): void
    {
        /** @var SessionInterface&Stub $session */
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn(null);

        $guard = new SessionGuard($session);
        $request = new ServerRequest(method: 'GET', uri: '/account');

        self::assertNull($guard->authenticate($request));
    }

    /**
     * F12.9: previously the guard silently dropped non-Identity
     * implementations (AnonymousIdentity, custom domain identities)
     * — `login()` succeeded but the session stayed empty, breaking
     * the next request invisibly. The guard now throws LogicException
     * with a precise diagnostic.
     */
    #[Test]
    public function storeIdentityRejectsNonIdentityImplementation(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(false);

        $guard = new SessionGuard($session);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SessionGuard::storeIdentity expected');

        $guard->updateIdentity(new AnonymousIdentity());
    }
}
