<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Dev\DevPermissiveGate;

#[CoversClass(DevPermissiveGate::class)]
final class DevPermissiveGateTest extends TestCase
{
    public function testAllowsGrantsAuthenticatedIdentity(): void
    {
        $gate = new DevPermissiveGate();

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        self::assertTrue($gate->allows($identity, 'any.permission'));
    }

    public function testAllowsDeniesUnauthenticatedIdentity(): void
    {
        $gate = new DevPermissiveGate();

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        self::assertFalse($gate->allows($identity, 'any.permission'));
    }

    public function testDeniesIsInverseOfAllows(): void
    {
        $gate = new DevPermissiveGate();

        $authenticatedIdentity = $this->createStub(IdentityInterface::class);
        $authenticatedIdentity->method('isAuthenticated')->willReturn(true);

        $unauthenticatedIdentity = $this->createStub(IdentityInterface::class);
        $unauthenticatedIdentity->method('isAuthenticated')->willReturn(false);

        self::assertFalse($gate->denies($authenticatedIdentity, 'admin.access'));
        self::assertTrue($gate->denies($unauthenticatedIdentity, 'admin.access'));
    }

    public function testAllowsIgnoresPermissionName(): void
    {
        $gate = new DevPermissiveGate();

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        // Any permission is allowed for authenticated users
        self::assertTrue($gate->allows($identity, 'admin.delete'));
        self::assertTrue($gate->allows($identity, 'system.reboot'));
        self::assertTrue($gate->allows($identity, 'nuclear.launch'));
    }

    public function testAllowsAcceptsNullContext(): void
    {
        $gate = new DevPermissiveGate();

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        self::assertTrue($gate->allows($identity, 'test', null));
    }
}
