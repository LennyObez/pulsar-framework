<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Security\AllowlistChecker;

#[CoversClass(AllowlistChecker::class)]
final class AllowlistCheckerTest extends TestCase
{
    #[Test]
    public function emptyAllowlistAllowsAllIps(): void
    {
        $checker = new AllowlistChecker([]);

        self::assertTrue($checker->isAllowed('192.168.1.100'));
        self::assertTrue($checker->isAllowed('10.0.0.1'));
        self::assertTrue($checker->isAllowed('255.255.255.255'));
    }

    #[Test]
    public function exactIpMatchAllowsAccess(): void
    {
        $checker = new AllowlistChecker(['192.168.1.100']);

        self::assertTrue($checker->isAllowed('192.168.1.100'));
    }

    #[Test]
    public function exactIpMatchDeniesNonMatchingIp(): void
    {
        $checker = new AllowlistChecker(['192.168.1.100']);

        self::assertFalse($checker->isAllowed('192.168.1.101'));
    }

    #[Test]
    public function cidrNotationAllowsIpsInRange(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertTrue($checker->isAllowed('10.0.0.1'));
        self::assertTrue($checker->isAllowed('10.255.255.255'));
        self::assertTrue($checker->isAllowed('10.50.100.200'));
    }

    #[Test]
    public function cidrNotationDeniesIpsOutsideRange(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertFalse($checker->isAllowed('192.168.1.1'));
        self::assertFalse($checker->isAllowed('11.0.0.1'));
        self::assertFalse($checker->isAllowed('9.255.255.255'));
    }

    #[Test]
    public function cidr16MatchesCorrectly(): void
    {
        $checker = new AllowlistChecker(['192.168.0.0/16']);

        self::assertTrue($checker->isAllowed('192.168.0.1'));
        self::assertTrue($checker->isAllowed('192.168.255.255'));
        self::assertFalse($checker->isAllowed('192.167.0.1'));
        self::assertFalse($checker->isAllowed('192.169.0.1'));
    }

    #[Test]
    public function cidr24MatchesCorrectly(): void
    {
        $checker = new AllowlistChecker(['192.168.1.0/24']);

        self::assertTrue($checker->isAllowed('192.168.1.0'));
        self::assertTrue($checker->isAllowed('192.168.1.255'));
        self::assertFalse($checker->isAllowed('192.168.2.0'));
        self::assertFalse($checker->isAllowed('192.168.0.255'));
    }

    #[Test]
    public function cidr32MatchesExactIp(): void
    {
        $checker = new AllowlistChecker(['192.168.1.100/32']);

        self::assertTrue($checker->isAllowed('192.168.1.100'));
        self::assertFalse($checker->isAllowed('192.168.1.101'));
        self::assertFalse($checker->isAllowed('192.168.1.99'));
    }

    #[Test]
    public function cidr28MatchesCorrectSubnet(): void
    {
        // 192.168.1.0/28 = 192.168.1.0 - 192.168.1.15
        $checker = new AllowlistChecker(['192.168.1.0/28']);

        self::assertTrue($checker->isAllowed('192.168.1.0'));
        self::assertTrue($checker->isAllowed('192.168.1.15'));
        self::assertTrue($checker->isAllowed('192.168.1.7'));
        self::assertFalse($checker->isAllowed('192.168.1.16'));
        self::assertFalse($checker->isAllowed('192.168.1.255'));
    }

    #[Test]
    public function ipv6ExactMatchAllowsAccess(): void
    {
        $checker = new AllowlistChecker(['::1']);

        self::assertTrue($checker->isAllowed('::1'));
    }

    #[Test]
    public function ipv6ExactMatchDeniesNonMatchingIp(): void
    {
        $checker = new AllowlistChecker(['::1']);

        self::assertFalse($checker->isAllowed('::2'));
    }

    #[Test]
    public function ipv6CidrNotationAllowsIpsInRange(): void
    {
        $checker = new AllowlistChecker(['::1/128']);

        self::assertTrue($checker->isAllowed('::1'));
    }

    #[Test]
    public function ipv6CidrNotationDeniesIpsOutsideRange(): void
    {
        $checker = new AllowlistChecker(['::1/128']);

        self::assertFalse($checker->isAllowed('::2'));
    }

    #[Test]
    public function ipv6Cidr64MatchesCorrectly(): void
    {
        $checker = new AllowlistChecker(['2001:db8::/32']);

        self::assertTrue($checker->isAllowed('2001:db8::1'));
        self::assertTrue($checker->isAllowed('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'));
        self::assertFalse($checker->isAllowed('2001:db9::1'));
    }

    #[Test]
    public function multipleAllowlistEntriesCheckAll(): void
    {
        $checker = new AllowlistChecker([
            '192.168.1.0/24',
            '10.0.0.0/8',
            '172.16.0.1',
        ]);

        self::assertTrue($checker->isAllowed('192.168.1.50'));
        self::assertTrue($checker->isAllowed('10.255.0.1'));
        self::assertTrue($checker->isAllowed('172.16.0.1'));
        self::assertFalse($checker->isAllowed('8.8.8.8'));
    }

    #[Test]
    public function invalidIpReturnsFalse(): void
    {
        $checker = new AllowlistChecker(['192.168.1.0/24']);

        self::assertFalse($checker->isAllowed('invalid-ip'));
        self::assertFalse($checker->isAllowed(''));
        self::assertFalse($checker->isAllowed('999.999.999.999'));
    }

    #[Test]
    public function invalidCidrSubnetReturnsFalse(): void
    {
        $checker = new AllowlistChecker(['invalid-cidr/24']);

        self::assertFalse($checker->isAllowed('192.168.1.100'));
    }

    #[Test]
    public function ipv4AndIpv6DoNotMatch(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8']);

        self::assertFalse($checker->isAllowed('::1'));
    }

    #[Test]
    public function ipv6AndIpv4DoNotMatch(): void
    {
        $checker = new AllowlistChecker(['::1/128']);

        self::assertFalse($checker->isAllowed('127.0.0.1'));
    }

    #[Test]
    public function localhostIpv4AllowedByDefault(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8', '::1/128']);

        self::assertTrue($checker->isAllowed('127.0.0.1'));
        self::assertTrue($checker->isAllowed('127.0.0.255'));
        self::assertTrue($checker->isAllowed('127.255.255.255'));
    }

    #[Test]
    public function localhostIpv6AllowedByDefault(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8', '::1/128']);

        self::assertTrue($checker->isAllowed('::1'));
    }

    #[Test]
    public function cidrWithZeroBitsAllowsAll(): void
    {
        $checker = new AllowlistChecker(['0.0.0.0/0']);

        self::assertTrue($checker->isAllowed('0.0.0.0'));
        self::assertTrue($checker->isAllowed('255.255.255.255'));
        self::assertTrue($checker->isAllowed('192.168.1.1'));
        self::assertTrue($checker->isAllowed('10.0.0.1'));
    }

    #[Test]
    public function cidrWithPartialByteMatchWorks(): void
    {
        // 192.168.1.0/25 = 192.168.1.0 - 192.168.1.127
        $checker = new AllowlistChecker(['192.168.1.0/25']);

        self::assertTrue($checker->isAllowed('192.168.1.0'));
        self::assertTrue($checker->isAllowed('192.168.1.127'));
        self::assertTrue($checker->isAllowed('192.168.1.64'));
        self::assertFalse($checker->isAllowed('192.168.1.128'));
        self::assertFalse($checker->isAllowed('192.168.1.255'));
    }

    #[Test]
    public function cidr20MatchesCorrectly(): void
    {
        // 172.16.0.0/20 = 172.16.0.0 - 172.16.15.255
        $checker = new AllowlistChecker(['172.16.0.0/20']);

        self::assertTrue($checker->isAllowed('172.16.0.0'));
        self::assertTrue($checker->isAllowed('172.16.15.255'));
        self::assertTrue($checker->isAllowed('172.16.8.100'));
        self::assertFalse($checker->isAllowed('172.16.16.0'));
        self::assertFalse($checker->isAllowed('172.16.255.0'));
    }
}
