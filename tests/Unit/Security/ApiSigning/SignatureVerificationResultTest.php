<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ApiSigning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ApiSigning\SignatureVerificationResult;

#[CoversClass(SignatureVerificationResult::class)]
final class SignatureVerificationResultTest extends TestCase
{
    #[Test]
    public function successCreatesValidResult(): void
    {
        $result = SignatureVerificationResult::success();

        self::assertTrue($result->valid);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function failureCreatesInvalidResultWithReason(): void
    {
        $result = SignatureVerificationResult::failure('Expired timestamp');

        self::assertFalse($result->valid);
        self::assertSame('Expired timestamp', $result->reason);
    }

    #[Test]
    public function failureWithEmptyReason(): void
    {
        $result = SignatureVerificationResult::failure('');

        self::assertFalse($result->valid);
        self::assertSame('', $result->reason);
    }
}
