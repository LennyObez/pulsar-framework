<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Validation\UrlSafetyValidator;
use Pulsar\Security\Validation\UrlValidationResult;

#[CoversClass(UrlSafetyValidator::class)]
#[CoversClass(UrlValidationResult::class)]
final class UrlSafetyValidatorTest extends TestCase
{
    // --- Public URLs: should pass ---

    #[Test]
    #[DataProvider('safeUrls')]
    public function allowsPublicUrls(string $url): void
    {
        $result = UrlSafetyValidator::validate($url);

        self::assertTrue($result->safe, "Expected URL to be safe: {$url} — reason: {$result->reason}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeUrls(): iterable
    {
        yield 'https public' => ['https://example.com/health'];
        yield 'http public' => ['http://api.example.com:8080/status'];
        yield 'public IP' => ['https://93.184.216.34/check'];
    }

    // --- Private/reserved IPs: should be blocked ---

    #[Test]
    #[DataProvider('privateUrls')]
    public function rejectsPrivateNetworkUrls(string $url): void
    {
        $result = UrlSafetyValidator::validate($url);

        self::assertFalse($result->safe, "Expected URL to be rejected: {$url}");
        self::assertNotEmpty($result->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateUrls(): iterable
    {
        // RFC 1918
        yield '10.x.x.x' => ['http://10.0.0.1/health'];
        yield '172.16.x.x' => ['http://172.16.0.1/api'];
        yield '172.31.x.x' => ['http://172.31.255.255/api'];
        yield '192.168.x.x' => ['http://192.168.1.1/status'];

        // Loopback
        yield '127.0.0.1' => ['http://127.0.0.1/admin'];
        yield '127.0.0.2' => ['http://127.0.0.2/health'];

        // Link-local
        yield '169.254.x.x' => ['http://169.254.1.1/status'];

        // Cloud metadata
        yield 'AWS metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ECS metadata' => ['http://169.254.170.2/v3/task'];

        // Current network
        yield '0.0.0.0' => ['http://0.0.0.0/'];
    }

    // --- Cloud metadata always blocked, even with allowPrivateNetworks ---

    #[Test]
    public function alwaysBlocksCloudMetadataEvenWithPrivateAllowed(): void
    {
        $result = UrlSafetyValidator::validate('http://169.254.169.254/latest/', allowPrivateNetworks: true);

        self::assertFalse($result->safe);
        self::assertStringContainsString('cloud metadata', $result->reason);
    }

    // --- Private IPs allowed when flag is set ---

    #[Test]
    public function allowsPrivateIpsWhenFlagSet(): void
    {
        $result = UrlSafetyValidator::validate('http://10.0.0.1/health', allowPrivateNetworks: true);

        self::assertTrue($result->safe);
    }

    // --- Invalid URLs ---

    #[Test]
    #[DataProvider('invalidUrls')]
    public function rejectsInvalidUrls(string $url): void
    {
        $result = UrlSafetyValidator::validate($url);

        self::assertFalse($result->safe);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'no scheme' => ['example.com/path'];
        yield 'ftp scheme' => ['ftp://server.internal/file'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://evil.com/'];
    }

    // --- IPv6 ---

    #[Test]
    public function rejectsIpv6Loopback(): void
    {
        $result = UrlSafetyValidator::validate('http://[::1]/health');

        self::assertFalse($result->safe);
    }

    #[Test]
    public function rejectsIpv6UniqueLocal(): void
    {
        $result = UrlSafetyValidator::validate('http://[fd12:3456:789a::1]/api');

        self::assertFalse($result->safe);
    }

    #[Test]
    public function rejectsIpv6LinkLocal(): void
    {
        $result = UrlSafetyValidator::validate('http://[fe80::1]/health');

        self::assertFalse($result->safe);
    }

    // --- UrlValidationResult ---

    #[Test]
    public function allowedResultIsSafe(): void
    {
        $result = UrlValidationResult::allowed();

        self::assertTrue($result->safe);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function rejectedResultIsNotSafe(): void
    {
        $result = UrlValidationResult::rejected('test reason');

        self::assertFalse($result->safe);
        self::assertSame('test reason', $result->reason);
    }
}
