<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Config\AppConfig;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\AuthGuardConfig;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\ConfigLoaderInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ErrorTrackingConfig;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\LoggingChannelConfig;
use Pulsar\Config\MetricsConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\RateLimitConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Config\TracingConfig;
use Pulsar\Config\TwoFactorConfig;

#[CoversClass(Api::class)]
final class ConfigApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function configLoaderInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ConfigLoaderInterface::class);
    }

    #[Test]
    public function configRepositoryIsPublicApi(): void
    {
        self::assertHasApiAttribute(ConfigRepository::class);
    }

    #[Test]
    public function environmentIsPublicApi(): void
    {
        self::assertHasApiAttribute(Environment::class);
    }

    #[Test]
    public function environmentModeIsPublicApi(): void
    {
        self::assertHasApiAttribute(EnvironmentMode::class);
    }

    #[Test]
    public function configExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(ConfigException::class);
    }

    #[Test]
    public function configManagerIsInternal(): void
    {
        self::assertHasInternalAttribute(ConfigManager::class);
    }

    #[Test]
    public function allConfigDtosArePublicApi(): void
    {
        $configClasses = [
            AppConfig::class,
            AuditConfig::class,
            AuthConfig::class,
            AuthGuardConfig::class,
            AuthorizationConfig::class,
            CircuitBreakerConfig::class,
            ConnectionConfig::class,
            CsrfConfig::class,
            DatabaseConfig::class,
            ErrorTrackingConfig::class,
            FeatureFlagConfig::class,
            HealthCheckConfig::class,
            LoggingChannelConfig::class,
            MetricsConfig::class,
            ObservabilityConfig::class,
            RateLimitConfig::class,
            ResilienceConfig::class,
            RetryConfig::class,
            SchedulerConfig::class,
            SecurityConfig::class,
            SecurityHeadersConfig::class,
            SessionConfig::class,
            TenancyConfig::class,
            TenantDatabaseConfig::class,
            TracingConfig::class,
            TwoFactorConfig::class,
        ];

        foreach ($configClasses as $class) {
            self::assertHasApiAttribute($class);
            self::assertClassIsReadonly($class);
        }
    }

    #[Test]
    public function configDtosHaveFromArrayFactory(): void
    {
        $classesWithFromArray = [
            AppConfig::class,
            AuditConfig::class,
            AuthConfig::class,
            CircuitBreakerConfig::class,
            ConnectionConfig::class,
            CsrfConfig::class,
            DatabaseConfig::class,
            ErrorTrackingConfig::class,
            FeatureFlagConfig::class,
            HealthCheckConfig::class,
            MetricsConfig::class,
            ObservabilityConfig::class,
            RateLimitConfig::class,
            ResilienceConfig::class,
            RetryConfig::class,
            SchedulerConfig::class,
            SecurityConfig::class,
            SecurityHeadersConfig::class,
            SessionConfig::class,
            TenancyConfig::class,
            TenantDatabaseConfig::class,
            TracingConfig::class,
            TwoFactorConfig::class,
        ];

        foreach ($classesWithFromArray as $class) {
            self::assertStaticFactoryExists($class, 'fromArray');
        }
    }
}
