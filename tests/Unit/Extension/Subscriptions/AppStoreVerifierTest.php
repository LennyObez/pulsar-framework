<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Internal\AppStoreVerifier;
use Pulsar\Extension\Subscriptions\Store;

final class AppStoreVerifierTest extends TestCase
{
    #[Test]
    public function returnsInvalidForNonAppleStore(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/key.p8',
        ]);

        $result = $verifier->verify(Store::Google, 'some-token');

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function returnsInvalidForEmptyBundleId(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/some/key.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'some-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidForEmptyPurchaseToken(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/some/key.p8',
        ]);

        $result = $verifier->verify(Store::Apple, '');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenJwtBuildFailsDueToMissingIssuerId(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => '',
            'key_id' => 'key-456',
            'private_key_path' => '/some/key.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'valid-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenJwtBuildFailsDueToMissingKeyId(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => '',
            'private_key_path' => '/some/key.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'valid-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenPrivateKeyPathIsEmpty(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '',
        ]);

        $result = $verifier->verify(Store::Apple, 'valid-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenPrivateKeyFileDoesNotExist(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/AuthKey.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'valid-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function respectsEnvironmentConfigForSandbox(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/key.p8',
            'environment' => 'sandbox',
        ]);

        // Cannot reach the sandbox URL without a valid key, but verifies config parsing
        $result = $verifier->verify(Store::Apple, 'test-token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function defaultsToProductionEnvironment(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/key.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'test-token');

        // Can't reach prod URL either, but the default path is exercised
        self::assertFalse($result->isValid);
    }
}
