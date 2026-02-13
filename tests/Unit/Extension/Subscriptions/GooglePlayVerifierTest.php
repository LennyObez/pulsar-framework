<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Internal\GooglePlayVerifier;
use Pulsar\Extension\Subscriptions\Store;

final class GooglePlayVerifierTest extends TestCase
{
    #[Test]
    public function returnsInvalidForNonGoogleStore(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        $result = $verifier->verify(Store::Apple, 'some-token');

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function returnsInvalidForEmptyPackageName(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '/some/path.json',
        ]);

        $result = $verifier->verify(Store::Google, 'some-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidForEmptyPurchaseToken(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/some/path.json',
        ]);

        $result = $verifier->verify(Store::Google, '');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidForMissingServiceAccountFile(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/service-account.json',
        ]);

        $result = $verifier->verify(Store::Google, 'valid-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidForEmptyServiceAccountPath(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '',
        ]);

        $result = $verifier->verify(Store::Google, 'valid-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function configDefaultsAreApplied(): void
    {
        $verifier = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/path.json',
        ]);

        // Should not throw even without api_base_url — defaults are handled internally
        $result = $verifier->verify(Store::Google, 'test-token');

        self::assertFalse($result->isValid);
    }
}
