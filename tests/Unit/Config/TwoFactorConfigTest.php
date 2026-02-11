<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TwoFactorConfig;

#[CoversClass(TwoFactorConfig::class)]
final class TwoFactorConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new TwoFactorConfig();

        self::assertFalse($config->enabled);
        self::assertSame('Pulsar', $config->issuer);
        self::assertSame(6, $config->codeDigits);
        self::assertSame(30, $config->codePeriod);
        self::assertSame(1, $config->verificationWindow);
        self::assertSame(8, $config->recoveryCodeCount);
        self::assertSame(8, $config->recoveryCodeBytes);
        self::assertSame(15, $config->stepUpTimeoutMinutes);
        self::assertSame(2, $config->recoveryCodeAlgorithmVersion);
        self::assertFalse($config->allowInMemory);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $config = new TwoFactorConfig(
            enabled: true,
            issuer: 'MyApp',
            codeDigits: 8,
            codePeriod: 60,
            verificationWindow: 2,
            recoveryCodeCount: 12,
            recoveryCodeBytes: 16,
            stepUpTimeoutMinutes: 30,
            recoveryCodeAlgorithmVersion: 3,
            allowInMemory: true,
        );

        self::assertTrue($config->enabled);
        self::assertSame('MyApp', $config->issuer);
        self::assertSame(8, $config->codeDigits);
        self::assertSame(60, $config->codePeriod);
        self::assertSame(2, $config->verificationWindow);
        self::assertSame(12, $config->recoveryCodeCount);
        self::assertSame(16, $config->recoveryCodeBytes);
        self::assertSame(30, $config->stepUpTimeoutMinutes);
        self::assertSame(3, $config->recoveryCodeAlgorithmVersion);
        self::assertTrue($config->allowInMemory);
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = TwoFactorConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('Pulsar', $config->issuer);
        self::assertSame(6, $config->codeDigits);
        self::assertSame(30, $config->codePeriod);
        self::assertSame(1, $config->verificationWindow);
        self::assertSame(8, $config->recoveryCodeCount);
        self::assertSame(8, $config->recoveryCodeBytes);
        self::assertSame(15, $config->stepUpTimeoutMinutes);
        self::assertSame(2, $config->recoveryCodeAlgorithmVersion);
        self::assertFalse($config->allowInMemory);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = TwoFactorConfig::fromArray([
            'enabled' => true,
            'issuer' => 'BankApp',
            'code_digits' => 8,
            'code_period' => 60,
            'verification_window' => 2,
            'recovery_code_count' => 16,
            'recovery_code_bytes' => 12,
            'step_up_timeout_minutes' => 5,
            'recovery_code_algorithm_version' => 3,
            'allow_in_memory' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('BankApp', $config->issuer);
        self::assertSame(8, $config->codeDigits);
        self::assertSame(60, $config->codePeriod);
        self::assertSame(2, $config->verificationWindow);
        self::assertSame(16, $config->recoveryCodeCount);
        self::assertSame(12, $config->recoveryCodeBytes);
        self::assertSame(5, $config->stepUpTimeoutMinutes);
        self::assertSame(3, $config->recoveryCodeAlgorithmVersion);
        self::assertTrue($config->allowInMemory);
    }

    #[Test]
    public function fromArrayCoercesEnabledViaBoolCast(): void
    {
        $configTruthy = TwoFactorConfig::fromArray(['enabled' => 1]);
        self::assertTrue($configTruthy->enabled);

        $configFalsy = TwoFactorConfig::fromArray(['enabled' => 0]);
        self::assertFalse($configFalsy->enabled);

        $configString = TwoFactorConfig::fromArray(['enabled' => 'yes']);
        self::assertTrue($configString->enabled);
    }

    #[Test]
    public function fromArrayCoercesAllowInMemoryViaBoolCast(): void
    {
        $configTruthy = TwoFactorConfig::fromArray(['allow_in_memory' => 1]);
        self::assertTrue($configTruthy->allowInMemory);

        $configFalsy = TwoFactorConfig::fromArray(['allow_in_memory' => '']);
        self::assertFalse($configFalsy->allowInMemory);
    }

    #[Test]
    public function fromArrayFallsBackOnNonStringIssuer(): void
    {
        $config = TwoFactorConfig::fromArray([
            'issuer' => 42,
        ]);

        self::assertSame('Pulsar', $config->issuer);
    }

    #[Test]
    public function fromArrayCoercesNumericStringIntegers(): void
    {
        $config = TwoFactorConfig::fromArray([
            'code_digits' => '8',
            'code_period' => '60',
            'verification_window' => '2',
            'recovery_code_count' => '16',
            'recovery_code_bytes' => '12',
            'step_up_timeout_minutes' => '30',
            'recovery_code_algorithm_version' => '3',
        ]);

        self::assertSame(8, $config->codeDigits);
        self::assertSame(60, $config->codePeriod);
        self::assertSame(2, $config->verificationWindow);
        self::assertSame(16, $config->recoveryCodeCount);
        self::assertSame(12, $config->recoveryCodeBytes);
        self::assertSame(30, $config->stepUpTimeoutMinutes);
        self::assertSame(3, $config->recoveryCodeAlgorithmVersion);
    }

    #[Test]
    public function fromArrayFallsBackOnNonNumericStringIntegers(): void
    {
        $config = TwoFactorConfig::fromArray([
            'code_digits' => 'six',
            'code_period' => 'thirty',
            'verification_window' => 'one',
            'recovery_code_count' => 'many',
            'recovery_code_bytes' => 'some',
            'step_up_timeout_minutes' => 'long',
            'recovery_code_algorithm_version' => 'latest',
        ]);

        self::assertSame(6, $config->codeDigits);
        self::assertSame(30, $config->codePeriod);
        self::assertSame(1, $config->verificationWindow);
        self::assertSame(8, $config->recoveryCodeCount);
        self::assertSame(8, $config->recoveryCodeBytes);
        self::assertSame(15, $config->stepUpTimeoutMinutes);
        self::assertSame(2, $config->recoveryCodeAlgorithmVersion);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = TwoFactorConfig::fromArray([
            'enabled' => null,
            'issuer' => null,
            'code_digits' => null,
            'code_period' => null,
            'verification_window' => null,
            'recovery_code_count' => null,
            'recovery_code_bytes' => null,
            'step_up_timeout_minutes' => null,
            'recovery_code_algorithm_version' => null,
            'allow_in_memory' => null,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('Pulsar', $config->issuer);
        self::assertSame(6, $config->codeDigits);
        self::assertSame(30, $config->codePeriod);
        self::assertSame(1, $config->verificationWindow);
        self::assertSame(8, $config->recoveryCodeCount);
        self::assertSame(8, $config->recoveryCodeBytes);
        self::assertSame(15, $config->stepUpTimeoutMinutes);
        self::assertSame(2, $config->recoveryCodeAlgorithmVersion);
        self::assertFalse($config->allowInMemory);
    }

    /**
     * @return iterable<string, array{string, mixed, int}>
     */
    public static function integerCoercionProvider(): iterable
    {
        yield 'int value' => ['code_digits', 10, 10];
        yield 'numeric string' => ['code_digits', '10', 10];
        yield 'float numeric' => ['code_digits', 10.5, 10];
        yield 'non-numeric string' => ['code_digits', 'abc', 6];
        yield 'array value' => ['code_digits', [10], 6];
        yield 'null value' => ['code_digits', null, 6];
        yield 'bool true' => ['code_digits', true, 6];
    }

    #[Test]
    #[DataProvider('integerCoercionProvider')]
    public function fromArrayHandlesCodeDigitsCoercion(string $key, mixed $value, int $expected): void
    {
        $config = TwoFactorConfig::fromArray([$key => $value]);

        self::assertSame($expected, $config->codeDigits);
    }

    #[Test]
    public function fromArrayPreservesExtraKeysSilently(): void
    {
        $config = TwoFactorConfig::fromArray([
            'enabled' => true,
            'unknown_key' => 'should_be_ignored',
            'another_random' => 42,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('Pulsar', $config->issuer);
    }

    #[Test]
    public function fromArrayWithEmptyStringIssuer(): void
    {
        $config = TwoFactorConfig::fromArray([
            'issuer' => '',
        ]);

        self::assertSame('', $config->issuer);
    }
}
