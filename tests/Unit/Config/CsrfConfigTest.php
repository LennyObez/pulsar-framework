<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CsrfConfig;

#[CoversClass(CsrfConfig::class)]
final class CsrfConfigTest extends TestCase
{
    #[Test]
    public function constructorStoresProperties(): void
    {
        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 64,
            headerName: 'X-Custom-Token',
            formFieldName: '_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'required',
        );

        self::assertTrue($config->enabled);
        self::assertSame(64, $config->tokenLength);
        self::assertSame('X-Custom-Token', $config->headerName);
        self::assertSame('_token', $config->formFieldName);
        self::assertSame(['https://example.com'], $config->trustedOrigins);
        self::assertSame('required', $config->originValidation);
    }

    #[Test]
    public function constructorDefaultsForOptionalProperties(): void
    {
        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        self::assertSame([], $config->trustedOrigins);
        self::assertSame('optional', $config->originValidation);
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = CsrfConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(32, $config->tokenLength);
        self::assertSame('X-CSRF-Token', $config->headerName);
        self::assertSame('_csrf_token', $config->formFieldName);
        self::assertSame([], $config->trustedOrigins);
        self::assertSame('optional', $config->originValidation);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = CsrfConfig::fromArray([
            'enabled' => false,
            'token_length' => 64,
            'header_name' => 'X-Request-Token',
            'form_field_name' => '__request_token',
            'trusted_origins' => ['https://example.com', 'https://app.example.com:8443'],
            'origin_validation' => 'required',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(64, $config->tokenLength);
        self::assertSame('X-Request-Token', $config->headerName);
        self::assertSame('__request_token', $config->formFieldName);
        self::assertSame(['https://example.com', 'https://app.example.com:8443'], $config->trustedOrigins);
        self::assertSame('required', $config->originValidation);
    }

    #[Test]
    public function fromArrayEnabledDefaultsToTrue(): void
    {
        $config = CsrfConfig::fromArray([]);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayEnabledCoercesFalsy(): void
    {
        $config = CsrfConfig::fromArray(['enabled' => 0]);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayEnabledCoercesTruthy(): void
    {
        $config = CsrfConfig::fromArray(['enabled' => 'yes']);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayCoercesNumericTokenLength(): void
    {
        $config = CsrfConfig::fromArray([
            'token_length' => '64',
        ]);

        self::assertSame(64, $config->tokenLength);
    }

    #[Test]
    public function fromArrayFallsBackOnNonNumericTokenLength(): void
    {
        $config = CsrfConfig::fromArray([
            'token_length' => 'long',
        ]);

        self::assertSame(32, $config->tokenLength);
    }

    #[Test]
    public function fromArrayFallsBackOnNonStringHeaderName(): void
    {
        $config = CsrfConfig::fromArray([
            'header_name' => 42,
        ]);

        self::assertSame('X-CSRF-Token', $config->headerName);
    }

    #[Test]
    public function fromArrayFallsBackOnNonStringFormFieldName(): void
    {
        $config = CsrfConfig::fromArray([
            'form_field_name' => true,
        ]);

        self::assertSame('_csrf_token', $config->formFieldName);
    }

    #[Test]
    public function fromArrayFiltersTrustedOriginsToStringsOnly(): void
    {
        $config = CsrfConfig::fromArray([
            'trusted_origins' => [
                'https://example.com',
                42,
                null,
                true,
                'https://other.com',
            ],
        ]);

        self::assertSame(['https://example.com', 'https://other.com'], $config->trustedOrigins);
    }

    #[Test]
    public function fromArrayHandlesNonArrayTrustedOrigins(): void
    {
        $config = CsrfConfig::fromArray([
            'trusted_origins' => 'https://example.com',
        ]);

        self::assertSame([], $config->trustedOrigins);
    }

    #[Test]
    public function fromArrayHandlesNullTrustedOrigins(): void
    {
        $config = CsrfConfig::fromArray([
            'trusted_origins' => null,
        ]);

        self::assertSame([], $config->trustedOrigins);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validOriginValidationProvider(): iterable
    {
        yield 'off' => ['off', 'off'];
        yield 'optional' => ['optional', 'optional'];
        yield 'required' => ['required', 'required'];
    }

    #[Test]
    #[DataProvider('validOriginValidationProvider')]
    public function fromArrayAcceptsValidOriginValidationValues(string $input, string $expected): void
    {
        $config = CsrfConfig::fromArray([
            'origin_validation' => $input,
        ]);

        self::assertSame($expected, $config->originValidation);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOriginValidationProvider(): iterable
    {
        yield 'unknown string' => ['strict'];
        yield 'integer' => [1];
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'uppercase' => ['REQUIRED'];
    }

    #[Test]
    #[DataProvider('invalidOriginValidationProvider')]
    public function fromArrayFallsBackOnInvalidOriginValidation(mixed $input): void
    {
        $config = CsrfConfig::fromArray([
            'origin_validation' => $input,
        ]);

        self::assertSame('optional', $config->originValidation);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = CsrfConfig::fromArray([
            'enabled' => null,
            'token_length' => null,
            'header_name' => null,
            'form_field_name' => null,
            'trusted_origins' => null,
            'origin_validation' => null,
        ]);

        // null ?? true yields true, so (bool) true = true
        self::assertTrue($config->enabled);
        self::assertSame(32, $config->tokenLength);
        self::assertSame('X-CSRF-Token', $config->headerName);
        self::assertSame('_csrf_token', $config->formFieldName);
        self::assertSame([], $config->trustedOrigins);
        self::assertSame('optional', $config->originValidation);
    }

    #[Test]
    public function fromArrayWithEmptyTrustedOriginsArray(): void
    {
        $config = CsrfConfig::fromArray([
            'trusted_origins' => [],
        ]);

        self::assertSame([], $config->trustedOrigins);
    }

    #[Test]
    public function fromArrayPreservesStringIndexKeysInTrustedOrigins(): void
    {
        $config = CsrfConfig::fromArray([
            'trusted_origins' => [
                'primary' => 'https://primary.com',
                'secondary' => 'https://secondary.com',
            ],
        ]);

        self::assertSame(['https://primary.com', 'https://secondary.com'], $config->trustedOrigins);
    }

    #[Test]
    public function fromArrayTokenLengthWithFloatCoercion(): void
    {
        $config = CsrfConfig::fromArray([
            'token_length' => 48.9,
        ]);

        self::assertSame(48, $config->tokenLength);
    }
}
