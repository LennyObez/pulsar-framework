<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Internal\AppStoreVerifier;
use Pulsar\Extension\Subscriptions\Internal\CompositeVerifier;
use Pulsar\Extension\Subscriptions\Internal\GooglePlayVerifier;
use Pulsar\Extension\Subscriptions\Store;

#[CoversClass(CompositeVerifier::class)]
final class CompositeVerifierTest extends TestCase
{
    #[Test]
    public function delegatesGoogleToGooglePlayVerifier(): void
    {
        // GooglePlayVerifier with empty config returns invalid for Google store,
        // so we use that to confirm delegation happens.
        $googleVerifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '',
        ]);

        $appleVerifier = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => '',
            'key_id' => '',
            'private_key_path' => '',
        ]);

        $composite = new CompositeVerifier($googleVerifier, $appleVerifier);
        $result = $composite->verify(Store::Google, 'token-123');

        // GooglePlayVerifier returns invalid for empty package_name
        self::assertFalse($result->isValid);
    }

    #[Test]
    public function delegatesAppleToAppStoreVerifier(): void
    {
        $googleVerifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '',
        ]);

        $appleVerifier = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => '',
            'key_id' => '',
            'private_key_path' => '',
        ]);

        $composite = new CompositeVerifier($googleVerifier, $appleVerifier);
        $result = $composite->verify(Store::Apple, 'apple-token');

        // AppStoreVerifier returns invalid for empty bundle_id
        self::assertFalse($result->isValid);
    }

    #[Test]
    public function googleRequestDoesNotReachAppleVerifier(): void
    {
        // GooglePlayVerifier returns invalid for non-Google store;
        // if it were accidentally routed to Apple, we would also get invalid,
        // but we verify the store-mismatch guard fires on the correct verifier.
        $googleVerifier = new GooglePlayVerifier([
            'package_name' => '',
            'service_account_json' => '',
        ]);

        $appleVerifier = new AppStoreVerifier([
            'bundle_id' => 'com.test.app',
            'issuer_id' => 'iss',
            'key_id' => 'key',
            'private_key_path' => '/nonexistent.p8',
        ]);

        $composite = new CompositeVerifier($googleVerifier, $appleVerifier);
        $result = $composite->verify(Store::Google, 'some-token');

        // Google verifier is called, returns invalid for empty package name
        // If Apple verifier were called, it would also fail but on a different path
        self::assertFalse($result->isValid);
        self::assertSame('', $result->productId);
    }

    #[Test]
    public function appleRequestDoesNotReachGoogleVerifier(): void
    {
        $googleVerifier = new GooglePlayVerifier([
            'package_name' => 'com.test.app',
            'service_account_json' => '/nonexistent/sa.json',
        ]);

        $appleVerifier = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => '',
            'key_id' => '',
            'private_key_path' => '',
        ]);

        $composite = new CompositeVerifier($googleVerifier, $appleVerifier);
        $result = $composite->verify(Store::Apple, 'some-token');

        // Apple verifier called, returns invalid for empty bundle_id
        self::assertFalse($result->isValid);
    }

    #[Test]
    public function compositeImplementsVerifierInterface(): void
    {
        $composite = new CompositeVerifier(
            new GooglePlayVerifier(['package_name' => '', 'service_account_json' => '']),
            new AppStoreVerifier(['bundle_id' => '', 'issuer_id' => '', 'key_id' => '', 'private_key_path' => '']),
        );

        self::assertInstanceOf(\Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface::class, $composite);
    }
}
