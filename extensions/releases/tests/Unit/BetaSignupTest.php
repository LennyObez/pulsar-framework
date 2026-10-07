<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\DeviceType;

use function strlen;

final class BetaSignupTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $signedUp = new DateTimeImmutable('2026-01-01');
        $invited = new DateTimeImmutable('2026-01-15');

        $signup = new BetaSignup(
            id: 'signup-abc',
            email: 'test@example.com',
            deviceType: DeviceType::Both,
            cameraBrands: ['Canon', 'Sony'],
            signedUpAt: $signedUp,
            invitedAt: $invited,
            inviteTokenHash: 'hash123',
        );

        self::assertSame('signup-abc', $signup->id);
        self::assertSame('test@example.com', $signup->email);
        self::assertSame(DeviceType::Both, $signup->deviceType);
        self::assertSame(['Canon', 'Sony'], $signup->cameraBrands);
        self::assertSame($signedUp, $signup->signedUpAt);
        self::assertSame($invited, $signup->invitedAt);
        self::assertSame('hash123', $signup->inviteTokenHash);
    }

    #[Test]
    public function createGeneratesIdAndSetsDefaults(): void
    {
        $signup = BetaSignup::create(
            email: 'user@example.com',
            deviceType: DeviceType::Android,
            cameraBrands: ['Nikon'],
        );

        self::assertSame(32, strlen($signup->id));
        self::assertSame('user@example.com', $signup->email);
        self::assertSame(DeviceType::Android, $signup->deviceType);
        self::assertSame(['Nikon'], $signup->cameraBrands);
        self::assertInstanceOf(DateTimeImmutable::class, $signup->signedUpAt);
        self::assertNull($signup->invitedAt);
        self::assertNull($signup->inviteTokenHash);
    }

    #[Test]
    public function createWithEmptyCameraBrands(): void
    {
        $signup = BetaSignup::create('user@test.com', DeviceType::Ios);

        self::assertSame([], $signup->cameraBrands);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $s1 = BetaSignup::create('a@b.com', DeviceType::Android);
        $s2 = BetaSignup::create('c@d.com', DeviceType::Ios);

        self::assertNotSame($s1->id, $s2->id);
    }

    #[Test]
    public function inviteReturnsNewInstanceWithTokenAndTimestamp(): void
    {
        $signup = BetaSignup::create('user@test.com', DeviceType::Both);

        self::assertNull($signup->invitedAt);
        self::assertNull($signup->inviteTokenHash);

        $invited = $signup->invite('invite-hash-abc');

        self::assertNotNull($invited->invitedAt);
        self::assertSame('invite-hash-abc', $invited->inviteTokenHash);
        self::assertNull($signup->invitedAt);
        self::assertNull($signup->inviteTokenHash);
        self::assertSame($signup->id, $invited->id);
        self::assertSame($signup->email, $invited->email);
    }
}
