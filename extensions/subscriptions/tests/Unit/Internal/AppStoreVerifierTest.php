<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Internal\AppStoreVerifier;
use Pulsar\Extension\Subscriptions\Store;

#[CoversClass(AppStoreVerifier::class)]
final class AppStoreVerifierTest extends TestCase
{
    #[Test]
    public function returnsInvalidForNonAppleStore(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/tmp/fake-key.p8',
        ]);

        $result = $verifier->verify(Store::Google, 'some-token');

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertSame('', $result->productId);
    }

    #[Test]
    public function returnsInvalidForEmptyBundleId(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/tmp/fake-key.p8',
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
            'private_key_path' => '/tmp/fake-key.p8',
        ]);

        $result = $verifier->verify(Store::Apple, '');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidForMissingConfig(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => '',
            'issuer_id' => '',
            'key_id' => '',
            'private_key_path' => '',
        ]);

        $result = $verifier->verify(Store::Apple, 'token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenPrivateKeyPathDoesNotExist(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => 'key-456',
            'private_key_path' => '/nonexistent/path/AuthKey.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'some-token');

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function returnsInvalidWhenMissingIssuerId(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => '',
            'key_id' => 'key-456',
            'private_key_path' => '/tmp/fake.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'token');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function returnsInvalidWhenMissingKeyId(): void
    {
        $verifier = new AppStoreVerifier([
            'bundle_id' => 'com.example.app',
            'issuer_id' => 'issuer-123',
            'key_id' => '',
            'private_key_path' => '/tmp/fake.p8',
        ]);

        $result = $verifier->verify(Store::Apple, 'token');

        self::assertFalse($result->isValid);
    }
}
