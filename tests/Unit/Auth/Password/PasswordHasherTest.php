<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Password;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    /** Create a hasher with cheap parameters for fast tests. */
    private function createTestHasher(): PasswordHasher
    {
        return new PasswordHasher(
            memoryCost: 256,
            timeCost: 1,
            threads: 1,
            allowWeakParameters: true,
        );
    }

    #[Test]
    public function hashReturnsNonEmptyStringDifferentFromInput(): void
    {
        $hasher = $this->createTestHasher();
        $password = 'my-secret-password';

        $hash = $hasher->hash($password);

        self::assertNotEmpty($hash);
        self::assertNotSame($password, $hash);
    }

    #[Test]
    public function verifyReturnsTrueForCorrectPassword(): void
    {
        $hasher = $this->createTestHasher();
        $password = 'correct-horse-battery-staple';

        $hash = $hasher->hash($password);

        self::assertTrue($hasher->verify($password, $hash));
    }

    #[Test]
    public function verifyReturnsFalseForWrongPassword(): void
    {
        $hasher = $this->createTestHasher();

        $hash = $hasher->hash('correct-password');

        self::assertFalse($hasher->verify('wrong-password', $hash));
    }

    #[Test]
    public function needsRehashReturnsFalseForFreshlyHashedPassword(): void
    {
        $hasher = $this->createTestHasher();

        $hash = $hasher->hash('some-password');

        self::assertFalse($hasher->needsRehash($hash));
    }

    #[Test]
    public function defaultsUseOwaspRecommendedParameters(): void
    {
        $hasher = new PasswordHasher(allowWeakParameters: true);

        self::assertSame(PasswordHasher::OWASP_MEMORY_COST, 19_456);
        self::assertSame(PasswordHasher::OWASP_TIME_COST, 2);
        self::assertSame(PasswordHasher::OWASP_THREADS, 1);
    }

    #[Test]
    public function rejectsMemoryCostBelowFloor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('memory_cost must be at least');

        new PasswordHasher(memoryCost: 1024);
    }

    #[Test]
    public function rejectsTimeCostBelowFloor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('time_cost must be at least');

        new PasswordHasher(timeCost: 1);
    }

    #[Test]
    public function allowWeakParametersBypassesValidation(): void
    {
        $hasher = new PasswordHasher(
            memoryCost: 256,
            timeCost: 1,
            threads: 1,
            allowWeakParameters: true,
        );

        $hash = $hasher->hash('test-password');

        self::assertTrue($hasher->verify('test-password', $hash));
    }

    #[Test]
    public function needsRehashReturnsTrueWhenParametersChange(): void
    {
        $cheapHasher = new PasswordHasher(
            memoryCost: 256,
            timeCost: 1,
            threads: 1,
            allowWeakParameters: true,
        );
        $strongerHasher = new PasswordHasher(
            memoryCost: 512,
            timeCost: 2,
            threads: 1,
            allowWeakParameters: true,
        );

        $hash = $cheapHasher->hash('some-password');

        self::assertTrue($strongerHasher->needsRehash($hash));
    }
}
