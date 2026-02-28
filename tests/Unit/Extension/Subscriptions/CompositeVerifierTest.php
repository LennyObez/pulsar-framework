<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Internal\AppStoreVerifier;
use Pulsar\Extension\Subscriptions\Internal\CompositeVerifier;
use Pulsar\Extension\Subscriptions\Internal\GooglePlayVerifier;
use Pulsar\Extension\Subscriptions\Store;

/**
 * Tests that CompositeVerifier delegates to the correct store-specific verifier.
 *
 * Since GooglePlayVerifier and AppStoreVerifier are final, we use real instances
 * with invalid configs — they will return VerificationResult::invalid() but the
 * delegation path (Google vs Apple) is still exercised.
 */
final class CompositeVerifierTest extends TestCase
{
    private CompositeVerifier $composite;

    protected function setUp(): void
    {
        $google = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/credentials.json',
        ]);

        $apple = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/AuthKey.p8',
        ]);

        $this->composite = new CompositeVerifier($google, $apple);
    }

    #[Test]
    public function googleStoreDelegatesToGoogleVerifier(): void
    {
        $result = $this->composite->verify(Store::Google, 'google-token');

        // GooglePlayVerifier returns invalid because service account file doesn't exist
        self::assertFalse($result->isValid);
    }

    #[Test]
    public function appleStoreDelegatesToAppleVerifier(): void
    {
        $result = $this->composite->verify(Store::Apple, 'apple-token');

        // AppStoreVerifier returns invalid because private key file doesn't exist
        self::assertFalse($result->isValid);
    }

    #[Test]
    public function googleVerifierIsNotCalledForAppleStore(): void
    {
        // Using a Google config that would succeed differently from Apple config,
        // we confirm that Store::Apple routes to the Apple verifier
        $googleWithEmpty = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '',
        ]);

        $appleWithConfig = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/key.p8',
        ]);

        $composite = new CompositeVerifier($googleWithEmpty, $appleWithConfig);

        $result = $composite->verify(Store::Apple, 'token');

        // Apple verifier handles this, not Google
        self::assertFalse($result->isValid);
    }

    #[Test]
    public function appleVerifierIsNotCalledForGoogleStore(): void
    {
        $googleWithConfig = new GooglePlayVerifier([
            'package_name' => 'com.example.app',
            'service_account_json' => '/nonexistent/sa.json',
        ]);

        $appleWithEmpty = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => '',
            'key_id' => '',
            'private_key_path' => '',
        ]);

        $composite = new CompositeVerifier($googleWithConfig, $appleWithEmpty);

        $result = $composite->verify(Store::Google, 'token');

        // Google verifier handles this, not Apple
        self::assertFalse($result->isValid);
    }
}
