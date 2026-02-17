<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\RegressionInput;

#[CoversClass(RegressionInput::class)]
final class RegressionInputTest extends TestCase
{
    public function testConstruction(): void
    {
        $input = new RegressionInput(
            sessionIdleTimeout: 900,
            passwordMinLength: 12,
            hstsMaxAge: 31536000,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        self::assertSame(900, $input->sessionIdleTimeout);
        self::assertSame(12, $input->passwordMinLength);
        self::assertSame(31536000, $input->hstsMaxAge);
        self::assertTrue($input->encryptionAtRest);
        self::assertSame('always', $input->mfaScope);
        self::assertTrue($input->tamperEvidentAudit);
    }
}
