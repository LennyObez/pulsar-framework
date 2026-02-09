<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use function count;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;

#[CoversClass(RecoveryCodeGenerator::class)]
final class RecoveryCodeGeneratorTest extends TestCase
{
    private RecoveryCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RecoveryCodeGenerator();
    }

    #[Test]
    public function generateReturnsCorrectCountOfCodes(): void
    {
        $codes = $this->generator->generate(8);

        self::assertCount(8, $codes);

        $codesFive = $this->generator->generate(5);

        self::assertCount(5, $codesFive);
    }

    #[Test]
    public function eachCodeMatchesExpectedPattern(): void
    {
        $codes = $this->generator->generate(8);

        foreach ($codes as $code) {
            // 64-bit format: XXXX-XXXX-XXXX-XXXX (16 hex chars)
            self::assertMatchesRegularExpression(
                '/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/',
                $code,
            );
        }
    }

    #[Test]
    public function legacyGeneratorProduces32BitFormat(): void
    {
        $legacyGenerator = new RecoveryCodeGenerator(bytesPerCode: 4);
        $codes = $legacyGenerator->generate(4);

        foreach ($codes as $code) {
            // 32-bit format: XXXX-XXXX (8 hex chars)
            self::assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
        }
    }

    #[Test]
    public function generatedCodesAreUnique(): void
    {
        $codes = $this->generator->generate(16);

        self::assertSame(count($codes), count(array_unique($codes)));
    }

    #[Test]
    public function checksumProducesConsistentResult(): void
    {
        $code = 'ABCD-1234-EF56-7890';
        $checksum1 = RecoveryCodeGenerator::checksum($code);
        $checksum2 = RecoveryCodeGenerator::checksum($code);

        self::assertSame($checksum1, $checksum2);
        self::assertMatchesRegularExpression('/^[0-9A-F]{2}$/', $checksum1);
    }

    #[Test]
    public function canonicalizeStripsDelimitersAndUppercases(): void
    {
        self::assertSame('ABCD1234EF567890', RecoveryCodeGenerator::canonicalize('abcd-1234-ef56-7890'));
        self::assertSame('ABCD1234EF567890', RecoveryCodeGenerator::canonicalize('ABCD 1234 EF56 7890'));
    }
}
