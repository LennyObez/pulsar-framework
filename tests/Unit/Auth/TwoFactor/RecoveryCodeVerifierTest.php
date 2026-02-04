<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;

#[CoversClass(RecoveryCodeVerifier::class)]
final class RecoveryCodeVerifierTest extends TestCase
{
    private RecoveryCodeVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new RecoveryCodeVerifier();
    }

    #[Test]
    public function verifyReturnsIndexOfMatchedCode(): void
    {
        $codes = ['ABCD-1234', 'EF56-7890', 'DEAD-BEEF'];

        self::assertSame(0, $this->verifier->verify('ABCD-1234', $codes));
        self::assertSame(1, $this->verifier->verify('EF56-7890', $codes));
        self::assertSame(2, $this->verifier->verify('DEAD-BEEF', $codes));
    }

    #[Test]
    public function verifyReturnsNegativeOneForUnmatchedCode(): void
    {
        $codes = ['ABCD-1234', 'EF56-7890'];

        self::assertSame(-1, $this->verifier->verify('FFFF-FFFF', $codes));
    }

    #[Test]
    public function verifyIsCaseInsensitive(): void
    {
        $codes = ['ABCD-1234', 'EF56-7890'];

        self::assertSame(0, $this->verifier->verify('abcd-1234', $codes));
        self::assertSame(1, $this->verifier->verify('ef56-7890', $codes));
    }
}
