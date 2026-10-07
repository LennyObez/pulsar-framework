<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Security\AllowlistChecker;

#[CoversClass(AllowlistChecker::class)]
final class AllowlistCheckerTest extends TestCase
{
    #[Test]
    public function emptyCidrListAllowsAll(): void
    {
        $checker = new AllowlistChecker([]);

        self::assertTrue($checker->isAllowed('1.2.3.4'));
        self::assertTrue($checker->isAllowed('192.168.1.1'));
    }

    #[Test]
    public function localhostIpv4MatchesLoopbackCidr(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8']);

        self::assertTrue($checker->isAllowed('127.0.0.1'));
        self::assertTrue($checker->isAllowed('127.0.0.5'));
        self::assertFalse($checker->isAllowed('192.168.1.1'));
    }

    #[Test]
    public function ipv6LoopbackMatchesCidr(): void
    {
        $checker = new AllowlistChecker(['::1/128']);

        self::assertTrue($checker->isAllowed('::1'));
        self::assertFalse($checker->isAllowed('::2'));
    }

    #[Test]
    public function exactIpMatchWithoutCidr(): void
    {
        $checker = new AllowlistChecker(['10.0.0.5']);

        self::assertTrue($checker->isAllowed('10.0.0.5'));
        self::assertFalse($checker->isAllowed('10.0.0.6'));
    }

    #[Test]
    public function privateNetworkCidr(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertTrue($checker->isAllowed('10.0.0.1'));
        self::assertTrue($checker->isAllowed('10.255.255.255'));
        self::assertFalse($checker->isAllowed('11.0.0.1'));
    }

    #[Test]
    public function mixedIpVersionsDontMatch(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8']);

        // IPv6 should not match IPv4 CIDR
        self::assertFalse($checker->isAllowed('::1'));
    }

    #[Test]
    public function invalidIpReturnsFalse(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertFalse($checker->isAllowed('not-an-ip'));
    }

    #[Test]
    public function multipleCidrsAnyMatch(): void
    {
        $checker = new AllowlistChecker(['192.168.1.0/24', '10.0.0.0/8']);

        self::assertTrue($checker->isAllowed('192.168.1.50'));
        self::assertTrue($checker->isAllowed('10.5.5.5'));
        self::assertFalse($checker->isAllowed('172.16.0.1'));
    }
}
