<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\DeviceType;

use function strlen;

final class BetaSignupEntityTest extends TestCase
{
    #[Test]
    public function createGeneratesIdAndSetsTimestamp(): void
    {
        $signup = BetaSignup::create(
            email: 'user@example.com',
            deviceType: DeviceType::Android,
        );

        self::assertSame(32, strlen($signup->id));
        self::assertSame('user@example.com', $signup->email);
        self::assertSame(DeviceType::Android, $signup->deviceType);
        self::assertSame([], $signup->cameraBrands);
        self::assertInstanceOf(DateTimeImmutable::class, $signup->signedUpAt);
        self::assertNull($signup->invitedAt);
        self::assertNull($signup->inviteTokenHash);
    }

    #[Test]
    public function createAcceptsCameraBrands(): void
    {
        $signup = BetaSignup::create(
            email: 'photographer@example.com',
            deviceType: DeviceType::Both,
            cameraBrands: ['Canon', 'Nikon', 'Sony'],
        );

        self::assertSame(['Canon', 'Nikon', 'Sony'], $signup->cameraBrands);
    }

    #[Test]
    public function inviteRecordsTimestampAndHash(): void
    {
        $signup = BetaSignup::create('user@example.com', DeviceType::Ios);

        self::assertNull($signup->invitedAt);
        self::assertNull($signup->inviteTokenHash);

        $invited = $signup->invite('hashed-invite-token');

        self::assertNotNull($invited->invitedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $invited->invitedAt);
        self::assertSame('hashed-invite-token', $invited->inviteTokenHash);
        self::assertSame($signup->id, $invited->id);
        self::assertSame($signup->email, $invited->email);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $signedUpAt = new DateTimeImmutable('2026-03-01');
        $invitedAt = new DateTimeImmutable('2026-03-10');

        $signup = new BetaSignup(
            id: 'bs-custom',
            email: 'custom@example.com',
            deviceType: DeviceType::Ios,
            cameraBrands: ['Fuji'],
            signedUpAt: $signedUpAt,
            invitedAt: $invitedAt,
            inviteTokenHash: 'hash-abc',
        );

        self::assertSame('bs-custom', $signup->id);
        self::assertSame('custom@example.com', $signup->email);
        self::assertSame(DeviceType::Ios, $signup->deviceType);
        self::assertSame(['Fuji'], $signup->cameraBrands);
        self::assertSame($signedUpAt, $signup->signedUpAt);
        self::assertSame($invitedAt, $signup->invitedAt);
        self::assertSame('hash-abc', $signup->inviteTokenHash);
    }
}
