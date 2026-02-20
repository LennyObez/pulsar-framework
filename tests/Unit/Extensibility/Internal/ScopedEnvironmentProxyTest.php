<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\Internal\ScopedEnvironmentProxy;
use Pulsar\Extensibility\TrustTier;

#[CoversClass(ScopedEnvironmentProxy::class)]
final class ScopedEnvironmentProxyTest extends TestCase
{
    private const string TEST_KEY = 'PULSAR_TEST_ENV_VAR';
    private const string TEST_SENSITIVE_KEY = 'PULSAR_MASTER_KEY';
    private const string TEST_DB_KEY = 'DB_PASSWORD';

    protected function setUp(): void
    {
        putenv(self::TEST_KEY . '=test_value');
        putenv(self::TEST_SENSITIVE_KEY . '=secret_master');
        putenv(self::TEST_DB_KEY . '=secret_db');
    }

    protected function tearDown(): void
    {
        putenv(self::TEST_KEY);
        putenv(self::TEST_SENSITIVE_KEY);
        putenv(self::TEST_DB_KEY);
    }

    #[Test]
    public function core_tier_can_read_any_env_variable(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Core,
            CapabilityPolicy::defaults(),
        );

        self::assertSame('test_value', $proxy->get(self::TEST_KEY));
        self::assertTrue($proxy->has(self::TEST_KEY));
    }

    #[Test]
    public function core_tier_can_read_sensitive_variables(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Core,
            CapabilityPolicy::defaults(),
        );

        self::assertSame('secret_master', $proxy->get(self::TEST_SENSITIVE_KEY));
        self::assertSame('secret_db', $proxy->get(self::TEST_DB_KEY));
    }

    #[Test]
    public function verified_tier_can_read_non_sensitive_variables(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Verified,
            CapabilityPolicy::defaults(),
        );

        self::assertSame('test_value', $proxy->get(self::TEST_KEY));
        self::assertTrue($proxy->has(self::TEST_KEY));
    }

    #[Test]
    public function verified_tier_cannot_read_sensitive_without_crypto_key_access(): void
    {
        // Default Verified tier does NOT have CryptoKeyAccess
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Verified,
            CapabilityPolicy::defaults(),
        );

        self::assertNull($proxy->get(self::TEST_SENSITIVE_KEY));
        self::assertFalse($proxy->has(self::TEST_SENSITIVE_KEY));
    }

    #[Test]
    public function verified_tier_can_read_sensitive_with_additional_crypto_key_access(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Verified,
            CapabilityPolicy::defaults(),
            [ExtensionCapability::CryptoKeyAccess],
        );

        self::assertSame('secret_master', $proxy->get(self::TEST_SENSITIVE_KEY));
        self::assertTrue($proxy->has(self::TEST_SENSITIVE_KEY));
    }

    #[Test]
    public function community_tier_cannot_read_env_variables(): void
    {
        // Community tier does NOT have EnvRead in default policy
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Community,
            CapabilityPolicy::defaults(),
        );

        self::assertNull($proxy->get(self::TEST_KEY));
        self::assertFalse($proxy->has(self::TEST_KEY));
    }

    #[Test]
    public function untrusted_tier_cannot_read_env_variables(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Untrusted,
            CapabilityPolicy::defaults(),
        );

        self::assertNull($proxy->get(self::TEST_KEY));
        self::assertFalse($proxy->has(self::TEST_KEY));
    }

    #[Test]
    public function community_with_additional_env_read_can_read_non_sensitive(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Community,
            CapabilityPolicy::defaults(),
            [ExtensionCapability::EnvRead],
        );

        self::assertSame('test_value', $proxy->get(self::TEST_KEY));
        self::assertTrue($proxy->has(self::TEST_KEY));
    }

    #[Test]
    public function community_with_env_read_cannot_read_sensitive(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Community,
            CapabilityPolicy::defaults(),
            [ExtensionCapability::EnvRead],
        );

        // Community does not have atLeast(Verified), so sensitive is denied
        self::assertNull($proxy->get(self::TEST_SENSITIVE_KEY));
        self::assertFalse($proxy->has(self::TEST_SENSITIVE_KEY));
    }

    #[Test]
    public function returns_null_for_nonexistent_variable(): void
    {
        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Core,
            CapabilityPolicy::defaults(),
        );

        self::assertNull($proxy->get('NONEXISTENT_VAR_12345'));
        self::assertFalse($proxy->has('NONEXISTENT_VAR_12345'));
    }

    #[Test]
    public function sensitive_prefix_matching_is_case_insensitive(): void
    {
        putenv('db_password=lower_case_secret');

        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Verified,
            CapabilityPolicy::defaults(),
        );

        // db_password should match DB_PASSWORD prefix (case-insensitive)
        self::assertNull($proxy->get('db_password'));
        self::assertFalse($proxy->has('db_password'));

        putenv('db_password');
    }

    #[Test]
    public function sensitive_prefix_matching_includes_suffixed_variants(): void
    {
        putenv('DB_PASSWORD_READONLY=readonly_secret');

        $proxy = new ScopedEnvironmentProxy(
            TrustTier::Verified,
            CapabilityPolicy::defaults(),
        );

        // DB_PASSWORD_READONLY starts with DB_PASSWORD_ so it is sensitive
        self::assertNull($proxy->get('DB_PASSWORD_READONLY'));
        self::assertFalse($proxy->has('DB_PASSWORD_READONLY'));

        putenv('DB_PASSWORD_READONLY');
    }
}
