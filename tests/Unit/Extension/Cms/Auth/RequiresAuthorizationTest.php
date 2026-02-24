<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Auth\CmsPermission;
use Pulsar\Extension\Cms\Auth\RequiresAuthorization;
use Pulsar\Http\Message\Response;

#[CoversClass(RequiresAuthorization::class)]
final class RequiresAuthorizationTest extends TestCase
{
    use RequiresAuthorization;

    private GateInterface&Stub $gate;

    protected function setUp(): void
    {
        $this->gate = $this->createStub(GateInterface::class);
    }

    private function makeRequest(?IdentityInterface $identity): ServerRequestInterface&Stub
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name) => $name === 'identity' ? $identity : null,
        );

        return $request;
    }

    // -- requireIdentity ------------------------------------------------------

    #[Test]
    public function requireIdentityReturnsIdentityWhenAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $result = $this->requireIdentity($this->makeRequest($identity));

        self::assertInstanceOf(IdentityInterface::class, $result);
    }

    #[Test]
    public function requireIdentityReturns401WhenNoIdentity(): void
    {
        $result = $this->requireIdentity($this->makeRequest(null));

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(401, $result->getStatusCode());
    }

    #[Test]
    public function requireIdentityReturns401WhenNotAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        $result = $this->requireIdentity($this->makeRequest($identity));

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(401, $result->getStatusCode());
    }

    // -- optionalIdentity -----------------------------------------------------

    #[Test]
    public function optionalIdentityReturnsIdentityWhenAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $result = $this->optionalIdentity($this->makeRequest($identity));

        self::assertSame($identity, $result);
    }

    #[Test]
    public function optionalIdentityReturnsNullWhenNoIdentity(): void
    {
        self::assertNull($this->optionalIdentity($this->makeRequest(null)));
    }

    #[Test]
    public function optionalIdentityReturnsNullWhenNotAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        self::assertNull($this->optionalIdentity($this->makeRequest($identity)));
    }

    // -- authorize ------------------------------------------------------------

    #[Test]
    public function authorizeReturnsNullWhenAllowed(): void
    {
        $this->gate->method('denies')->willReturn(false);
        $identity = $this->createStub(IdentityInterface::class);

        $result = $this->authorize($this->gate, $identity, CmsPermission::ContentView);

        self::assertNull($result);
    }

    #[Test]
    public function authorizeReturns403WhenDenied(): void
    {
        $this->gate->method('denies')->willReturn(true);
        $identity = $this->createStub(IdentityInterface::class);

        $result = $this->authorize($this->gate, $identity, CmsPermission::ContentView);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    #[Test]
    public function authorizePassesResourceToContext(): void
    {
        $this->gate->method('denies')->willReturn(false);
        $identity = $this->createStub(IdentityInterface::class);

        $result = $this->authorize($this->gate, $identity, CmsPermission::ContentUpdate, 'content:c-01');

        self::assertNull($result);
    }

    // -- verifyTenantAccess ---------------------------------------------------

    #[Test]
    public function verifyTenantAccessReturnsNullWhenIdentityHasNoTenant(): void
    {
        self::assertNull($this->verifyTenantAccess('tenant-01', null));
    }

    #[Test]
    public function verifyTenantAccessReturnsNullWhenTenantsMatch(): void
    {
        self::assertNull($this->verifyTenantAccess('tenant-01', 'tenant-01'));
    }

    #[Test]
    public function verifyTenantAccessReturns403WhenTenantsDiffer(): void
    {
        $result = $this->verifyTenantAccess('tenant-01', 'tenant-02');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    #[Test]
    public function verifyTenantAccessReturns403WhenResourceHasNoTenantButIdentityDoes(): void
    {
        $result = $this->verifyTenantAccess(null, 'tenant-01');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }
}
