<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\SessionMetadata;

#[CoversClass(SessionMetadata::class)]
final class SessionMetadataTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $meta = new SessionMetadata(
            createdAt: 1000,
            lastActivity: 2000,
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0',
            userId: 'user-42',
            fingerprint: 'fp-abc',
        );

        self::assertSame(1000, $meta->createdAt);
        self::assertSame(2000, $meta->lastActivity);
        self::assertSame('192.168.1.1', $meta->ipAddress);
        self::assertSame('Mozilla/5.0', $meta->userAgent);
        self::assertSame('user-42', $meta->userId);
        self::assertSame('fp-abc', $meta->fingerprint);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $meta = SessionMetadata::fromArray([
            'created_at' => 1000,
            'last_activity' => 2000,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'TestAgent',
            'user_id' => 'u-1',
            'fingerprint' => 'fp-1',
        ]);

        self::assertSame(1000, $meta->createdAt);
        self::assertSame(2000, $meta->lastActivity);
        self::assertSame('10.0.0.1', $meta->ipAddress);
        self::assertSame('TestAgent', $meta->userAgent);
        self::assertSame('u-1', $meta->userId);
        self::assertSame('fp-1', $meta->fingerprint);
    }

    #[Test]
    public function fromArrayHandlesNumericStringTimestamps(): void
    {
        $meta = SessionMetadata::fromArray([
            'created_at' => '1500',
            'last_activity' => '2500',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Agent',
        ]);

        self::assertSame(1500, $meta->createdAt);
        self::assertSame(2500, $meta->lastActivity);
    }

    #[Test]
    public function fromArrayHandlesNonNumericTimestampsGracefully(): void
    {
        $before = time();
        $meta = SessionMetadata::fromArray([
            'created_at' => 'invalid',
            'last_activity' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Agent',
        ]);
        $after = time();

        self::assertGreaterThanOrEqual($before, $meta->createdAt);
        self::assertLessThanOrEqual($after, $meta->createdAt);
    }

    #[Test]
    public function fromArrayDefaultsOptionalFieldsToNull(): void
    {
        $meta = SessionMetadata::fromArray([
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        self::assertNull($meta->userId);
        self::assertNull($meta->fingerprint);
    }

    #[Test]
    public function toArrayRoundTrips(): void
    {
        $original = new SessionMetadata(
            createdAt: 1000,
            lastActivity: 2000,
            ipAddress: '10.0.0.1',
            userAgent: 'Chrome',
            userId: 'u-1',
            fingerprint: 'fp-1',
        );

        $restored = SessionMetadata::fromArray($original->toArray());

        self::assertSame($original->createdAt, $restored->createdAt);
        self::assertSame($original->lastActivity, $restored->lastActivity);
        self::assertSame($original->ipAddress, $restored->ipAddress);
        self::assertSame($original->userAgent, $restored->userAgent);
        self::assertSame($original->userId, $restored->userId);
        self::assertSame($original->fingerprint, $restored->fingerprint);
    }

    #[Test]
    public function withLastActivityReturnsNewInstance(): void
    {
        $original = new SessionMetadata(
            createdAt: 1000,
            lastActivity: 2000,
            ipAddress: '127.0.0.1',
            userAgent: 'Test',
        );

        $updated = $original->withLastActivity(3000);

        self::assertSame(2000, $original->lastActivity);
        self::assertSame(3000, $updated->lastActivity);
        self::assertSame(1000, $updated->createdAt);
        self::assertSame('127.0.0.1', $updated->ipAddress);
    }

    #[Test]
    public function withUserIdReturnsNewInstance(): void
    {
        $original = new SessionMetadata(
            createdAt: 1000,
            lastActivity: 2000,
            ipAddress: '127.0.0.1',
            userAgent: 'Test',
        );

        $updated = $original->withUserId('user-99');

        self::assertNull($original->userId);
        self::assertSame('user-99', $updated->userId);
    }

    #[Test]
    public function withFingerprintReturnsNewInstance(): void
    {
        $original = new SessionMetadata(
            createdAt: 1000,
            lastActivity: 2000,
            ipAddress: '127.0.0.1',
            userAgent: 'Test',
        );

        $updated = $original->withFingerprint('fp-new');

        self::assertNull($original->fingerprint);
        self::assertSame('fp-new', $updated->fingerprint);
    }

    #[Test]
    public function withFingerprintCanClearFingerprint(): void
    {
        $original = new SessionMetadata(
            createdAt: 1000,
            lastActivity: 2000,
            ipAddress: '127.0.0.1',
            userAgent: 'Test',
            fingerprint: 'fp-old',
        );

        $cleared = $original->withFingerprint(null);

        self::assertSame('fp-old', $original->fingerprint);
        self::assertNull($cleared->fingerprint);
    }
}
