<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Security\AllowlistChecker;

final class AllowlistCheckerTest extends TestCase
{
    #[Test]
    public function allowsAllWhenCidrsEmpty(): void
    {
        $checker = new AllowlistChecker([]);

        self::assertTrue($checker->isAllowed('192.168.1.100'));
    }

    #[Test]
    public function allowsLocalhostIpv4(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8']);

        self::assertTrue($checker->isAllowed('127.0.0.1'));
        self::assertTrue($checker->isAllowed('127.0.0.2'));
    }

    #[Test]
    public function allowsLocalhostIpv6(): void
    {
        $checker = new AllowlistChecker(['::1/128']);

        self::assertTrue($checker->isAllowed('::1'));
    }

    #[Test]
    public function deniesOutOfRangeIp(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertFalse($checker->isAllowed('192.168.1.1'));
    }

    #[Test]
    public function allowsIpInRange(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertTrue($checker->isAllowed('10.255.255.255'));
        self::assertTrue($checker->isAllowed('10.0.0.1'));
    }

    #[Test]
    public function allowsExactIpMatch(): void
    {
        $checker = new AllowlistChecker(['192.168.1.100']);

        self::assertTrue($checker->isAllowed('192.168.1.100'));
        self::assertFalse($checker->isAllowed('192.168.1.101'));
    }

    #[Test]
    public function handlesInvalidIpGracefully(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8']);

        self::assertFalse($checker->isAllowed('not-an-ip'));
    }

    #[Test]
    public function checksMultipleCidrs(): void
    {
        $checker = new AllowlistChecker(['10.0.0.0/8', '172.16.0.0/12']);

        self::assertTrue($checker->isAllowed('10.1.2.3'));
        self::assertTrue($checker->isAllowed('172.16.5.10'));
        self::assertFalse($checker->isAllowed('192.168.1.1'));
    }

    #[Test]
    public function handlesMixedIpv4AndIpv6(): void
    {
        $checker = new AllowlistChecker(['127.0.0.1/8', '::1/128']);

        self::assertTrue($checker->isAllowed('127.0.0.1'));
        self::assertTrue($checker->isAllowed('::1'));
        self::assertFalse($checker->isAllowed('192.168.1.1'));
    }
}
