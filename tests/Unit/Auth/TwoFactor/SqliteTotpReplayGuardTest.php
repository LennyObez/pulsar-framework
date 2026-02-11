<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\SqliteTotpReplayGuard;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(SqliteTotpReplayGuard::class)]
final class SqliteTotpReplayGuardTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'totp_guard_');
        self::assertIsString($path);

        // Remove the temp file so SQLite creates it fresh
        unlink($path);
        $this->dbPath = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->dbPath . '-wal');
        @unlink($this->dbPath . '-shm');
    }

    #[Test]
    public function markUsedReturnsTrueForFirstUse(): void
    {
        $guard = new SqliteTotpReplayGuard($this->dbPath);

        $result = $guard->markUsed('user-001', TwoFactorPurpose::Login, 100, 1709800000);

        self::assertTrue($result);
    }

    #[Test]
    public function markUsedReturnsFalseForReplayedTimeStep(): void
    {
        $guard = new SqliteTotpReplayGuard($this->dbPath);

        $guard->markUsed('user-001', TwoFactorPurpose::Login, 100, 1709800000);
        $result = $guard->markUsed('user-001', TwoFactorPurpose::Login, 100, 1709800001);

        self::assertFalse($result);
    }

    #[Test]
    public function sameTimeStepDifferentPurposeIsAllowed(): void
    {
        $guard = new SqliteTotpReplayGuard($this->dbPath);

        self::assertTrue($guard->markUsed('user-001', TwoFactorPurpose::Login, 100, 1709800000));
        self::assertTrue($guard->markUsed('user-001', TwoFactorPurpose::StepUp, 100, 1709800000));
    }

    #[Test]
    public function sameTimeStepDifferentIdentityIsAllowed(): void
    {
        $guard = new SqliteTotpReplayGuard($this->dbPath);

        self::assertTrue($guard->markUsed('user-001', TwoFactorPurpose::Login, 100, 1709800000));
        self::assertTrue($guard->markUsed('user-002', TwoFactorPurpose::Login, 100, 1709800000));
    }

    #[Test]
    public function differentTimeStepSameIdentityIsAllowed(): void
    {
        $guard = new SqliteTotpReplayGuard($this->dbPath);

        self::assertTrue($guard->markUsed('user-001', TwoFactorPurpose::Login, 100, 1709800000));
        self::assertTrue($guard->markUsed('user-001', TwoFactorPurpose::Login, 101, 1709800030));
    }

    #[Test]
    public function probabilisticPruningDoesNotCauseErrors(): void
    {
        $guard = new SqliteTotpReplayGuard($this->dbPath);

        // Insert enough entries to trigger probabilistic pruning (1/20 chance)
        // With 200 inserts, pruning should fire ~10 times on average
        for ($i = 0; $i < 200; $i++) {
            $guard->markUsed('user-prune', TwoFactorPurpose::Login, $i, 1709800000 + $i);
        }

        // The guard should still work correctly
        self::assertTrue($guard->markUsed('user-prune', TwoFactorPurpose::Login, 999, 1709900000));
        self::assertFalse($guard->markUsed('user-prune', TwoFactorPurpose::Login, 999, 1709900001));
    }
}
