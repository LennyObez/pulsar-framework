<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\DeviceType;
use Pulsar\Extension\Releases\Internal\Persistence\DbBetaSignupRepository;

#[CoversClass(DbBetaSignupRepository::class)]
final class DbBetaSignupRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function signupRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'bs-001',
            'email' => 'user@example.com',
            'device_type' => 'android',
            'camera_brands' => '["Canon","Sony"]',
            'signed_up_at' => '2026-01-15T10:00:00+00:00',
            'invited_at' => null,
            'invite_token_hash' => null,
        ], $overrides);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveCallsExecuteWithUpsertQuery(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $signup = new BetaSignup(
            id: 'bs-001',
            email: 'user@example.com',
            deviceType: DeviceType::Android,
            cameraBrands: ['Canon', 'Sony'],
            signedUpAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            invitedAt: null,
            inviteTokenHash: null,
        );

        $repo = new DbBetaSignupRepository($db);
        $repo->save($signup);
    }

    // ── findByEmail ──────────────────────────────────────────────────

    #[Test]
    public function findByEmailReturnsSignupWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->signupRow()]));

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findByEmail('user@example.com');

        self::assertInstanceOf(BetaSignup::class, $result);
        self::assertSame('bs-001', $result->id);
        self::assertSame('user@example.com', $result->email);
        self::assertSame(DeviceType::Android, $result->deviceType);
        self::assertSame(['Canon', 'Sony'], $result->cameraBrands);
        self::assertSame('2026-01-15T10:00:00+00:00', $result->signedUpAt->format('c'));
        self::assertNull($result->invitedAt);
        self::assertNull($result->inviteTokenHash);
    }

    #[Test]
    public function findByEmailReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbBetaSignupRepository($db);

        self::assertNull($repo->findByEmail('missing@example.com'));
    }

    #[Test]
    public function findByEmailHydratesInvitedSignup(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->signupRow([
                'device_type' => 'ios',
                'camera_brands' => '["Nikon"]',
                'invited_at' => '2026-02-01T10:00:00+00:00',
                'invite_token_hash' => 'invite-hash-abc',
            ]),
        ]));

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findByEmail('user@example.com');

        self::assertInstanceOf(BetaSignup::class, $result);
        self::assertSame(DeviceType::Ios, $result->deviceType);
        self::assertSame(['Nikon'], $result->cameraBrands);
        self::assertNotNull($result->invitedAt);
        self::assertSame('invite-hash-abc', $result->inviteTokenHash);
    }

    // ── findAll ──────────────────────────────────────────────────────

    #[Test]
    public function findAllReturnsPaginatedResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 2]]),
            Result::fromArrays([
                $this->signupRow(['id' => 'bs-001']),
                $this->signupRow(['id' => 'bs-002', 'email' => 'other@example.com']),
            ]),
        );

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findAll(1, 20);

        self::assertSame(2, $result->total);
        self::assertCount(2, $result->items);
        self::assertSame(1, $result->currentPage);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findAllReturnsEmptyWhenNoneExist(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findAll(1, 20);

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function findAllWithMultiplePagesHasMore(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 25]]),
            Result::fromArrays([$this->signupRow()]),
        );

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findAll(1, 10);

        self::assertSame(25, $result->total);
        self::assertTrue($result->hasMore);
        self::assertSame(3, $result->lastPage);
    }

    #[Test]
    public function findAllClampsPageToMinimumOfOne(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findAll(-3, 20);

        self::assertSame(1, $result->currentPage);
    }

    #[Test]
    public function findAllClampsPerPageToMaximumOfOneHundred(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbBetaSignupRepository($db);
        $result = $repo->findAll(1, 200);

        self::assertSame(100, $result->perPage);
    }

    // ── countByEmailToday ────────────────────────────────────────────

    #[Test]
    public function countByEmailTodayReturnsCount(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([['total' => 2]]));

        $repo = new DbBetaSignupRepository($db);

        self::assertSame(2, $repo->countByEmailToday('user@example.com'));
    }

    #[Test]
    public function countByEmailTodayReturnsZeroWhenNoRows(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbBetaSignupRepository($db);

        self::assertSame(0, $repo->countByEmailToday('user@example.com'));
    }
}
