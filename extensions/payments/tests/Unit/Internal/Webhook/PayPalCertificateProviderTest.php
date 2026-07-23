<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Webhook\PayPalCertificateProvider;

final class PayPalCertificateProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'http (not https)' => ['http://api.paypal.com/cert.pem'];
        yield 'non-paypal host' => ['https://evil.example.com/cert.pem'];
        yield 'paypal in path only' => ['https://evil.example.com/api.paypal.com/cert.pem'];
        yield 'lookalike suffix' => ['https://notpaypal.com/cert.pem'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('rejectedUrls')]
    public function returnsNullForDisallowedCertUrlsWithoutFetching(string $url): void
    {
        // The cert URL comes from an attacker-controlled header. A disallowed URL
        // must be rejected by the allow-list BEFORE any network fetch (SSRF).
        $provider = new PayPalCertificateProvider();

        self::assertNull($provider->publicKeyPemFor($url));
    }
}
