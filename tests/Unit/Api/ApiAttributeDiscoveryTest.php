<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use function dirname;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function sprintf;

/**
 * Meta-test: ensures every class in src/ has either #[Api] or #[Internal],
 * and that the #[Api] whitelist matches expectations.
 */
#[CoversClass(Api::class)]
final class ApiAttributeDiscoveryTest extends TestCase
{
    /** @var list<class-string> Classes that should have #[Api] */
    private const array API_WHITELIST = [
        \Pulsar\Api\Api::class,
        \Pulsar\Api\Internal::class,
        \Pulsar\Auth\AuthManagerInterface::class,
        \Pulsar\Auth\Authorization\GateInterface::class,
        \Pulsar\Auth\Authorization\Permission::class,
        \Pulsar\Auth\Authorization\PolicyContext::class,
        \Pulsar\Auth\Authorization\PolicyInterface::class,
        \Pulsar\Auth\Authorization\Role::class,
        \Pulsar\Auth\Authorization\RoleRegistryInterface::class,
        \Pulsar\Auth\Exception\AuthenticationException::class,
        \Pulsar\Auth\Exception\AuthorizationException::class,
        \Pulsar\Auth\Guard\GuardInterface::class,
        \Pulsar\Auth\Guard\TokenResolverInterface::class,
        \Pulsar\Auth\Identity\AnonymousIdentity::class,
        \Pulsar\Auth\Identity\Identity::class,
        \Pulsar\Auth\Identity\IdentityInterface::class,
        \Pulsar\Auth\Identity\TwoFactorStatus::class,
        \Pulsar\Auth\Password\PasswordHasherInterface::class,
        \Pulsar\Auth\TwoFactor\TwoFactorManagerInterface::class,
        \Pulsar\Config\AppConfig::class,
        \Pulsar\Config\AuditConfig::class,
        \Pulsar\Config\AuthConfig::class,
        \Pulsar\Config\AuthGuardConfig::class,
        \Pulsar\Config\AuthorizationConfig::class,
        \Pulsar\Config\CircuitBreakerConfig::class,
        \Pulsar\Config\ConfigLoaderInterface::class,
        \Pulsar\Config\ConfigRepository::class,
        \Pulsar\Config\ConnectionConfig::class,
        \Pulsar\Config\CsrfConfig::class,
        \Pulsar\Config\DatabaseConfig::class,
        \Pulsar\Config\Environment::class,
        \Pulsar\Config\EnvironmentMode::class,
        \Pulsar\Config\ErrorTrackingConfig::class,
        \Pulsar\Config\Exception\ConfigException::class,
        \Pulsar\Config\FeatureFlagConfig::class,
        \Pulsar\Config\HealthCheckConfig::class,
        \Pulsar\Config\LoggingChannelConfig::class,
        \Pulsar\Config\MetricsConfig::class,
        \Pulsar\Config\ObservabilityConfig::class,
        \Pulsar\Config\RateLimitConfig::class,
        \Pulsar\Config\ResilienceConfig::class,
        \Pulsar\Config\RetryConfig::class,
        \Pulsar\Config\SchedulerConfig::class,
        \Pulsar\Config\SecurityConfig::class,
        \Pulsar\Config\SecurityHeadersConfig::class,
        \Pulsar\Config\SessionConfig::class,
        \Pulsar\Config\TenancyConfig::class,
        \Pulsar\Config\TenantDatabaseConfig::class,
        \Pulsar\Config\TracingConfig::class,
        \Pulsar\Config\TwoFactorConfig::class,
        \Pulsar\Console\CommandInterface::class,
        \Pulsar\Console\Exception\CommandNotFoundException::class,
        \Pulsar\Console\Exception\ConsoleException::class,
        \Pulsar\Console\ExitCode::class,
        \Pulsar\Console\InputInterface::class,
        \Pulsar\Console\OutputInterface::class,
        \Pulsar\Console\Verbosity::class,
        \Pulsar\Container\BindingType::class,
        \Pulsar\Container\ContainerInterface::class,
        \Pulsar\Container\Exception\ContainerException::class,
        \Pulsar\Container\Exception\NotFoundException::class,
        \Pulsar\Database\ConnectionInterface::class,
        \Pulsar\Database\ConnectionManagerInterface::class,
        \Pulsar\Database\Driver::class,
        \Pulsar\Database\Exception\DatabaseException::class,
        \Pulsar\Database\FetchMode::class,
        \Pulsar\Database\Migration\MigrationDirection::class,
        \Pulsar\Database\Migration\MigrationFile::class,
        \Pulsar\Database\Migration\MigrationInterface::class,
        \Pulsar\Database\Migration\MigrationRecord::class,
        \Pulsar\Database\Result::class,
        \Pulsar\Database\Row::class,
        \Pulsar\ErrorHandling\Exception\ErrorHandlingException::class,
        \Pulsar\ErrorHandling\ExceptionRendererInterface::class,
        \Pulsar\ErrorHandling\HttpException::class,
        \Pulsar\ErrorHandling\HttpExceptionInterface::class,
        \Pulsar\Extensibility\Exception\DependencyException::class,
        \Pulsar\Extensibility\Exception\ExtensionException::class,
        \Pulsar\Extensibility\Exception\ManifestException::class,
        \Pulsar\Extensibility\ExtensionInterface::class,
        \Pulsar\Extensibility\ExtensionLifecycle::class,
        \Pulsar\Extensibility\ExtensionManifest::class,
        \Pulsar\Extensibility\Manifest\ProvidesConfig::class,
        \Pulsar\Extensibility\Manifest\PulsarVersionConfig::class,
        \Pulsar\Extensibility\Manifest\RequiresConfig::class,
        \Pulsar\Extensibility\ServiceProviderInterface::class,
        \Pulsar\FeatureFlag\Exception\FeatureFlagException::class,
        \Pulsar\FeatureFlag\FeatureFlagManagerInterface::class,
        \Pulsar\FeatureFlag\FlagContext::class,
        \Pulsar\FeatureFlag\FlagDefinition::class,
        \Pulsar\FeatureFlag\FlagEvaluation::class,
        \Pulsar\FeatureFlag\FlagEvaluationReason::class,
        \Pulsar\FeatureFlag\FlagStorageDriver::class,
        \Pulsar\FeatureFlag\FlagStorageInterface::class,
        \Pulsar\FeatureFlag\FlagType::class,
        \Pulsar\Http\HeaderBag::class,
        \Pulsar\Http\Method::class,
        \Pulsar\Http\Middleware\MiddlewareInterface::class,
        \Pulsar\Http\RateLimit\RateLimitResult::class,
        \Pulsar\Http\Request::class,
        \Pulsar\Http\Response::class,
        \Pulsar\Http\ResponseEmitter::class,
        \Pulsar\Http\ResponseStatus::class,
        \Pulsar\Http\Validation\Rule\Between::class,
        \Pulsar\Http\Validation\Rule\Email::class,
        \Pulsar\Http\Validation\Rule\In::class,
        \Pulsar\Http\Validation\Rule\IntegerType::class,
        \Pulsar\Http\Validation\Rule\Max::class,
        \Pulsar\Http\Validation\Rule\MaxLength::class,
        \Pulsar\Http\Validation\Rule\Min::class,
        \Pulsar\Http\Validation\Rule\MinLength::class,
        \Pulsar\Http\Validation\Rule\Regex::class,
        \Pulsar\Http\Validation\Rule\Required::class,
        \Pulsar\Http\Validation\Rule\StringType::class,
        \Pulsar\Http\Validation\RuleInterface::class,
        \Pulsar\Http\Validation\ValidationException::class,
        \Pulsar\Http\Validation\ValidationResult::class,
        \Pulsar\Http\Validation\Validator::class,
        \Pulsar\Http\Validation\Violation::class,
        \Pulsar\Observability\ErrorTracking\ErrorFingerprint::class,
        \Pulsar\Observability\ErrorTracking\Exception\ErrorTrackingException::class,
        \Pulsar\Observability\Log\Exception\LogException::class,
        \Pulsar\Observability\Log\LogEntry::class,
        \Pulsar\Observability\Log\LogLevel::class,
        \Pulsar\Observability\Log\LogSinkInterface::class,
        \Pulsar\Observability\Metrics\MetricRegistry::class,
        \Pulsar\Observability\Metrics\MetricType::class,
        \Pulsar\Observability\Tracing\Exception\TracingException::class,
        \Pulsar\Observability\Tracing\Span::class,
        \Pulsar\Observability\Tracing\SpanProcessorInterface::class,
        \Pulsar\Observability\Tracing\SpanStatus::class,
        \Pulsar\Observability\Tracing\TraceContext::class,
        \Pulsar\Resilience\CircuitBreakerState::class,
        \Pulsar\Resilience\Exception\ResilienceException::class,
        \Pulsar\Resilience\HealthCheck\HealthCheckInterface::class,
        \Pulsar\Resilience\HealthCheck\HealthCheckResult::class,
        \Pulsar\Resilience\HealthCheck\HealthReport::class,
        \Pulsar\Resilience\HealthCheck\HealthStatus::class,
        \Pulsar\Resilience\Repair\RepairDiagnosis::class,
        \Pulsar\Resilience\Repair\RepairJobInterface::class,
        \Pulsar\Resilience\Repair\RepairResult::class,
        \Pulsar\Resilience\RetryPolicy::class,
        \Pulsar\Resilience\RetryResult::class,
        \Pulsar\Routing\MatchedRoute::class,
        \Pulsar\Routing\Route::class,
        \Pulsar\Routing\RouteGroup::class,
        \Pulsar\Routing\Router::class,
        \Pulsar\Routing\RoutingException::class,
        \Pulsar\Scheduler\CronFields::class,
        \Pulsar\Scheduler\Exception\SchedulerException::class,
        \Pulsar\Scheduler\JobContext::class,
        \Pulsar\Scheduler\JobEvent::class,
        \Pulsar\Scheduler\JobInterface::class,
        \Pulsar\Scheduler\JobResult::class,
        \Pulsar\Scheduler\JobStatus::class,
        \Pulsar\Scheduler\Schedule::class,
        \Pulsar\Scheduler\SchedulerTickResult::class,
        \Pulsar\Security\Audit\AuditEntry::class,
        \Pulsar\Security\Audit\AuditEvent::class,
        \Pulsar\Security\Audit\AuditLogger::class,
        \Pulsar\Security\Audit\AuditOutcome::class,
        \Pulsar\Security\Audit\AuditSinkInterface::class,
        \Pulsar\Security\Csrf\CsrfTokenManagerInterface::class,
        \Pulsar\Security\Exception\SecurityException::class,
        \Pulsar\Security\Session\SessionInterface::class,
        \Pulsar\Tenancy\Exception\TenancyException::class,
        \Pulsar\Tenancy\Tenant::class,
        \Pulsar\Tenancy\TenantDatabaseStrategy::class,
        \Pulsar\Tenancy\TenantResolverInterface::class,
        \Pulsar\Tenancy\TenantResolverStrategy::class,
    ];

    /** @var list<class-string> Classes that should have #[Internal] */
    private const array INTERNAL_WHITELIST = [
        \Pulsar\Extensibility\ExtensionRegistry::class,
        \Pulsar\Extensibility\ExtensionBootstrap::class,
        \Pulsar\Extensibility\ExtensionLoader::class,
        \Pulsar\Http\Middleware\MiddlewarePipeline::class,
        \Pulsar\Http\Middleware\MiddlewareRegistry::class,
        \Pulsar\Core\Kernel::class,
        \Pulsar\Core\Version::class,
        \Pulsar\Config\ConfigManager::class,
        \Pulsar\Config\ConfigOverrides::class,
        \Pulsar\Console\Application::class,
    ];

    #[Test]
    public function allApiWhitelistedClassesHaveApiAttribute(): void
    {
        foreach (self::API_WHITELIST as $class) {
            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(Api::class);
            self::assertNotEmpty(
                $attrs,
                sprintf('Whitelisted class %s must have #[Api] attribute', $class),
            );
        }
    }

    #[Test]
    public function allInternalWhitelistedClassesHaveInternalAttribute(): void
    {
        foreach (self::INTERNAL_WHITELIST as $class) {
            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(Internal::class);
            self::assertNotEmpty(
                $attrs,
                sprintf('Whitelisted class %s must have #[Internal] attribute', $class),
            );
        }
    }

    #[Test]
    public function noUnexpectedClassesHaveApiAttribute(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $apiClasses = [];

        foreach ($this->discoverClasses($srcDir) as $class) {
            $ref = new ReflectionClass($class);
            if ($ref->getAttributes(Api::class) !== []) {
                $apiClasses[] = $class;
            }
        }

        sort($apiClasses);
        $expectedSorted = self::API_WHITELIST;
        sort($expectedSorted);

        self::assertSame(
            $expectedSorted,
            $apiClasses,
            'Only whitelisted classes should have #[Api] attribute',
        );
    }

    /**
     * @return list<class-string>
     */
    private function discoverClasses(string $directory): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Extract namespace and class/interface/enum name
            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)
                && preg_match('/^(?:(?:final|readonly|abstract)\s+)*(?:class|interface|enum)\s+(\w+)/m', $content, $classMatch)
            ) {
                $fqcn = $nsMatch[1] . '\\' . $classMatch[1];
                if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                    $classes[] = $fqcn;
                }
            }
        }

        return $classes;
    }
}
