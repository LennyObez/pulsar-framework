<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Password;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    #[Test]
    public function hashReturnsNonEmptyStringDifferentFromInput(): void
    {
        $hasher = new PasswordHasher();
        $password = 'my-secret-password';

        $hash = $hasher->hash($password);

        self::assertNotEmpty($hash);
        self::assertNotSame($password, $hash);
    }

    #[Test]
    public function verifyReturnsTrueForCorrectPassword(): void
    {
        $hasher = new PasswordHasher();
        $password = 'correct-horse-battery-staple';

        $hash = $hasher->hash($password);

        self::assertTrue($hasher->verify($password, $hash));
    }

    #[Test]
    public function verifyReturnsFalseForWrongPassword(): void
    {
        $hasher = new PasswordHasher();

        $hash = $hasher->hash('correct-password');

        self::assertFalse($hasher->verify('wrong-password', $hash));
    }

    #[Test]
    public function needsRehashReturnsFalseForFreshlyHashedPassword(): void
    {
        $hasher = new PasswordHasher();

        $hash = $hasher->hash('some-password');

        self::assertFalse($hasher->needsRehash($hash));
    }
}
