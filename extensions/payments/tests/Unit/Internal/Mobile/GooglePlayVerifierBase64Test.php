<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Mobile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Internal\Mobile\GooglePlayVerifier;
use ReflectionMethod;

use function base64_encode;
use function rtrim;
use function str_contains;
use function strtr;

/**
 * Tests that GooglePlayVerifier uses base64url encoding (RFC 4648 S5) for JWT construction.
 *
 * The JWT spec (RFC 7519) requires base64url encoding without padding.
 * Standard base64 uses '+' and '/' which are not URL-safe and can break
 * token exchange with Google's OAuth2 endpoint.
 */
final class GooglePlayVerifierBase64Test extends TestCase
{
    #[Test]
    public function base64UrlEncodeProducesUrlSafeOutput(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $method = new ReflectionMethod($verifier, 'base64UrlEncode');

        // Data that would produce '+' and '/' in standard base64
        $testData = "\x3e\x3f\xfb\xff";

        $result = $method->invoke($verifier, $testData);

        self::assertIsString($result);
        self::assertFalse(
            str_contains($result, '+'),
            'base64url must not contain "+" character',
        );
        self::assertFalse(
            str_contains($result, '/'),
            'base64url must not contain "/" character',
        );
        self::assertFalse(
            str_contains($result, '='),
            'base64url must not contain padding "=" characters',
        );
    }

    #[Test]
    public function base64UrlEncodeMatchesRfc4648Spec(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $method = new ReflectionMethod($verifier, 'base64UrlEncode');

        $testData = '{"alg":"RS256","typ":"JWT"}';

        $result = $method->invoke($verifier, $testData);
        $expected = rtrim(strtr(base64_encode($testData), '+/', '-_'), '=');

        self::assertSame($expected, $result);
    }

    #[Test]
    #[DataProvider('base64UrlEncodingProvider')]
    public function base64UrlEncodeHandlesVariousInputs(string $input, string $expectedPrefix): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $method = new ReflectionMethod($verifier, 'base64UrlEncode');

        $result = $method->invoke($verifier, $input);
        self::assertIsString($result);

        self::assertTrue(
            str_starts_with($result, $expectedPrefix),
            "Expected '{$result}' to start with '{$expectedPrefix}'",
        );
        self::assertStringNotContainsString('+', $result);
        self::assertStringNotContainsString('/', $result);
        self::assertStringNotContainsString('=', $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function base64UrlEncodingProvider(): iterable
    {
        // These specific byte sequences are chosen because they produce
        // '+', '/', or '=' in standard base64 encoding.
        yield 'produces plus in standard base64' => ["\xfb\xe0", '-'];
        yield 'produces slash in standard base64' => ["\xff\xff", '__'];
        yield 'needs single pad in standard base64' => ['ab', 'YW'];
        yield 'needs double pad in standard base64' => ['a', 'YQ'];
        yield 'single byte' => ["\x00", 'AA'];
    }

    #[Test]
    public function base64UrlEncodeReturnsEmptyStringForEmptyInput(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $method = new ReflectionMethod($verifier, 'base64UrlEncode');

        $result = $method->invoke($verifier, '');

        self::assertSame('', $result);
    }

    #[Test]
    public function base64UrlEncodeIsConsistentWithAppStoreVerifier(): void
    {
        // Both verifiers should produce identical base64url output
        // for the same input, ensuring JWT interoperability.
        $googleVerifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $googleMethod = new ReflectionMethod($googleVerifier, 'base64UrlEncode');

        $testInputs = [
            '{"alg":"RS256","typ":"JWT"}',
            '{"iss":"test@example.iam.gserviceaccount.com","iat":1700000000}',
            "\x00\x01\x02\xff\xfe\xfd",
        ];

        foreach ($testInputs as $input) {
            $googleResult = $googleMethod->invoke($googleVerifier, $input);
            $expected = rtrim(strtr(base64_encode($input), '+/', '-_'), '=');

            self::assertSame(
                $expected,
                $googleResult,
                "base64url encoding mismatch for input: {$input}",
            );
        }
    }

    #[Test]
    public function verifyReturnsInvalidForMissingServiceAccountFile(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/service-account.json',
        ]);

        $result = $verifier->verify(MobileStore::Google, 'test-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function verifyReturnsInvalidForNonGoogleStore(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $result = $verifier->verify(MobileStore::Apple, 'test-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function verifyReturnsInvalidForEmptyPurchaseToken(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $result = $verifier->verify(MobileStore::Google, '');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function verifyReturnsInvalidForEmptyPackageName(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $result = $verifier->verify(MobileStore::Google, 'test-token');

        self::assertFalse($result->isValid);
    }
}
