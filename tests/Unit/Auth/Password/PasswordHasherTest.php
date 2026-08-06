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

    /**
     * The floor is policy owned by the validation layer. Enforcing it here turns a
     * rejected form field into an uncaught exception, and raising it later would
     * refuse every account already hashed under the old one.
     */
    #[Test]
    public function hashesAPasswordBelowTheFloorRatherThanThrowing(): void
    {
        $hasher = $this->createTestHasher();
        $password = str_repeat('a', PasswordHasher::MIN_LENGTH - 1);

        self::assertTrue($hasher->verify($password, $hasher->hash($password)));
    }

    #[Test]
    public function hashesAnEmptyPasswordRatherThanThrowing(): void
    {
        $hasher = $this->createTestHasher();

        self::assertTrue($hasher->verify('', $hasher->hash('')));
    }

    #[Test]
    public function hashesAPasswordExactlyAtTheFloor(): void
    {
        $hasher = $this->createTestHasher();
        $password = str_repeat('a', PasswordHasher::MIN_LENGTH);

        self::assertTrue($hasher->verify($password, $hasher->hash($password)));
    }

    #[Test]
    public function refusesToHashAboveTheLengthCap(): void
    {
        $hasher = $this->createTestHasher();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Password exceeds the 4096-character cap');

        $hasher->hash(str_repeat('a', PasswordHasher::MAX_LENGTH + 1));
    }

    #[Test]
    public function verifyRefusesACandidateAboveTheLengthCap(): void
    {
        $hasher = $this->createTestHasher();
        $hash = $hasher->hash(str_repeat('a', PasswordHasher::MAX_LENGTH));

        self::assertFalse($hasher->verify(str_repeat('a', PasswordHasher::MAX_LENGTH + 1), $hash));
    }

    /**
     * ASVS 4.0.3 §2.1.2 / §2.1.3. Bcrypt authenticates on the first 72 bytes, so
     * every password sharing a prefix of that length is one credential. The
     * assertions below are exactly the ones that fail against bcrypt.
     */
    #[Test]
    public function consumesTheWholePasswordPastBcryptsSeventyTwoByteLimit(): void
    {
        $hasher = $this->createTestHasher();
        $prefix = str_repeat('A', 72);
        $password = $prefix . 'and-a-divergent-suffix-of-29c';

        $hash = $hasher->hash($password);

        self::assertTrue($hasher->verify($password, $hash));
        self::assertFalse(
            $hasher->verify($prefix, $hash),
            'The first 72 bytes alone must not authenticate',
        );
        self::assertFalse(
            $hasher->verify($prefix . 'a-completely-different-suffix', $hash),
            'A shared 72-byte prefix must not make two passwords interchangeable',
        );

        // The defect this pins, still reachable through the algorithm it replaced.
        self::assertTrue(password_verify($prefix, password_hash($password, PASSWORD_BCRYPT)));
    }
}
