<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\Check\AuditLoggerReadinessCheck;
use Pulsar\Deploy\Check\CacheSettingsCheck;
use Pulsar\Deploy\Check\DebugModeCheck;
use Pulsar\Deploy\Check\DependencyIntegrityCheck;
use Pulsar\Deploy\Check\EnvironmentValidationCheck;
use Pulsar\Deploy\Check\FilesystemScanCheck;
use Pulsar\Deploy\Check\HealthEndpointCheck;
use Pulsar\Deploy\Check\Http3ReadinessCheck;
use Pulsar\Deploy\Check\HttpsReadinessCheck;
use Pulsar\Deploy\Check\IntegrityCheck;
use Pulsar\Deploy\Check\JitCheck;
use Pulsar\Deploy\Check\OpcacheCheck;
use Pulsar\Deploy\Check\RateLimitCheck;
use Pulsar\Deploy\Check\RequestSizeCheck;
use Pulsar\Deploy\Check\SecurityHeadersReadinessCheck;
use Pulsar\Deploy\Check\SeverityOverrideCheck;
use Pulsar\Deploy\Check\SkippedCheck;
use Pulsar\Deploy\Check\TrustedProxyCheck;
use Pulsar\Deploy\Check\TwoFactorRateLimiterReadinessCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheck;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\DeployCheckRunnerInterface;
use Pulsar\Deploy\DeploySeverity;
use Pulsar\Deploy\MaintenanceMode;
use Pulsar\Deploy\Middleware\MaintenanceModeMiddleware;
use Pulsar\Deploy\Runtime\FilesystemReader;
use Pulsar\Deploy\Runtime\PhpRuntime;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Http\Factory\StreamFactory;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function dirname;
use function is_dir;
use function mkdir;

#[Internal]
final readonly class DeployWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(DeployConfig::class)) {
            return;
        }

        /** @var DeployConfig $deployConfig */
        $deployConfig = $repository->get(DeployConfig::class);
        $container->instance(DeployConfig::class, $deployConfig);

        // Maintenance mode (@api MaintenanceMode + its 503 middleware). The flag
        // file lives under the single writable root (var/framework); the
        // maintenance:enable / :disable commands toggle it, and the middleware
        // short-circuits every request with a 503 while it is active. Binding
        // MaintenanceMode is also what lets bin/pulsar register those commands.
        // Skip gracefully when the storage directory cannot be created so a
        // read-only or misconfigured filesystem never blocks boot.
        $maintenanceStorage = var_path('framework');

        if (is_dir($maintenanceStorage) || @mkdir($maintenanceStorage, 0o750, true) || is_dir($maintenanceStorage)) {
            $maintenanceMode = new MaintenanceMode($maintenanceStorage);
            $container->instance(MaintenanceMode::class, $maintenanceMode);
            $middleware->pipe(new MaintenanceModeMiddleware(
                $maintenanceMode,
                new ResponseFactory(),
                new StreamFactory(),
            ));
        }

        $phpRuntime = new PhpRuntime();
        $container->instance(PhpRuntimeInterface::class, $phpRuntime);

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $repository->get(SecurityConfig::class);

        $environment = $configManager->environment();

        $deployCheck = new DeployCheck();

        $this->registerCheckOrSkip($deployCheck, 'debug-mode', $deployConfig, static fn(): DeployCheckInterface => new DebugModeCheck($appConfig));

        $this->registerCheckOrSkip($deployCheck, 'environment-values', $deployConfig, static fn(): DeployCheckInterface => new EnvironmentValidationCheck($environment));

        $this->registerCheckOrSkip($deployCheck, 'opcache', $deployConfig, static fn(): DeployCheckInterface => new OpcacheCheck($phpRuntime, new FilesystemReader()));

        $this->registerCheckOrSkip($deployCheck, 'jit', $deployConfig, static fn(): DeployCheckInterface => new JitCheck($phpRuntime));

        $this->registerCheckOrSkip($deployCheck, 'cache-settings', $deployConfig, static function () use ($container): ?DeployCheckInterface {
            if (!$container->has(FrameworkCache::class)) {
                return null;
            }
            /** @var FrameworkCache $cache */
            $cache = $container->get(FrameworkCache::class);

            return new CacheSettingsCheck($cache);
        });

        $this->registerCheckOrSkip($deployCheck, 'filesystem-scan', $deployConfig, static function () use ($container): ?DeployCheckInterface {
            if (!$container->has(FrameworkCache::class)) {
                return null;
            }
            /** @var FrameworkCache $cache */
            $cache = $container->get(FrameworkCache::class);

            return new FilesystemScanCheck($cache);
        });

        $this->registerCheckOrSkip($deployCheck, 'security-headers', $deployConfig, static fn(): DeployCheckInterface => new SecurityHeadersReadinessCheck($securityConfig->headers));

        $this->registerCheckOrSkip($deployCheck, 'https-readiness', $deployConfig, static fn(): DeployCheckInterface => new HttpsReadinessCheck($securityConfig));

        $this->registerCheckOrSkip($deployCheck, 'http3-readiness', $deployConfig, static fn(): DeployCheckInterface => new Http3ReadinessCheck($deployConfig));

        $this->registerCheckOrSkip($deployCheck, 'health-endpoint', $deployConfig, static fn() => new HealthEndpointCheck($router));

        $this->registerCheckOrSkip($deployCheck, 'rate-limiting', $deployConfig, static fn(): DeployCheckInterface => new RateLimitCheck($securityConfig));

        $this->registerCheckOrSkip($deployCheck, 'request-size-limits', $deployConfig, static fn(): DeployCheckInterface => new RequestSizeCheck($deployConfig));

        $this->registerCheckOrSkip($deployCheck, 'trusted-proxies', $deployConfig, static fn(): DeployCheckInterface => new TrustedProxyCheck($deployConfig));

        $this->registerCheckOrSkip($deployCheck, 'integrity', $deployConfig, static function () use ($container): ?DeployCheckInterface {
            if (!$container->has(IntegrityConfig::class)) {
                return null;
            }
            /** @var IntegrityConfig $integrityConfig */
            $integrityConfig = $container->get(IntegrityConfig::class);

            return new IntegrityCheck($integrityConfig);
        });

        $this->registerCheckOrSkip($deployCheck, 'audit-logger', $deployConfig, static fn(): DeployCheckInterface => new AuditLoggerReadinessCheck($container));

        $this->registerCheckOrSkip($deployCheck, 'two-factor-rate-limiter', $deployConfig, static fn(): DeployCheckInterface => new TwoFactorRateLimiterReadinessCheck($container));

        $projectRoot = $configManager->configPath() !== null ? dirname($configManager->configPath()) : '.';
        $this->registerCheckOrSkip($deployCheck, 'dependency-integrity', $deployConfig, static fn(): DeployCheckInterface => new DependencyIntegrityCheck($projectRoot));

        $container->instance(DeployCheck::class, $deployCheck);
        $container->instance(DeployCheckRunnerInterface::class, $deployCheck);
    }

    /**
     * Register a deploy check or a skipped stub based on config and dependency availability.
     *
     * @param Closure(): ?DeployCheckInterface $factory
     */
    private function registerCheckOrSkip(
        DeployCheck $deployCheck,
        string $name,
        DeployConfig $deployConfig,
        Closure $factory,
    ): void {
        $config = $deployConfig->checkConfig($name);

        if (!$config['enabled']) {
            return;
        }

        $check = $factory();

        if ($check === null) {
            $deployCheck->register(new SkippedCheck(
                $name,
                'Required dependency not configured',
                $config['severity'] === 'fail' ? CheckSeverity::Error : CheckSeverity::Warning,
            ));
            return;
        }

        $severity = DeploySeverity::from($config['severity']);

        // Wrap with severity override if configured
        $deployCheck->register(new SeverityOverrideCheck($check, $severity));
    }
}
