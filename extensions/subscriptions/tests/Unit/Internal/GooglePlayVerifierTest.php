<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Internal\GooglePlayVerifier;
use Pulsar\Extension\Subscriptions\Store;

#[CoversClass(GooglePlayVerifier::class)]
final class GooglePlayVerifierTest extends TestCase
{
    #[Test]
    public function returnsInvalidForNonGoogleStore(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/tmp/service-account.json',
        ]);

        $result = $verifier->verify(Store::Apple, 'some-token');

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertSame('', $result->productId);
    }

    #[Test]
    public function returnsInvalidForEmptyPackageName(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '/tmp/service-account.json',
        ]);

        $result = $verifier->verify(Store::Google, 'some-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidForEmptyPurchaseToken(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/tmp/service-account.json',
        ]);

        $result = $verifier->verify(Store::Google, '');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenServiceAccountFileDoesNotExist(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path/credentials.json',
        ]);

        $result = $verifier->verify(Store::Google, 'purchase-token-123');

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function returnsInvalidForMissingConfig(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '',
        ]);

        $result = $verifier->verify(Store::Google, 'token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenEmptyServiceAccountPath(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '',
        ]);

        $result = $verifier->verify(Store::Google, 'token');

        self::assertFalse($result->isValid);
    }
}
