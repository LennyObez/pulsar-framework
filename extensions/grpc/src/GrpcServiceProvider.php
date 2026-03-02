<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Grpc\Adapter\GrpcExtensionAdapter;
use Pulsar\Extension\Grpc\Adapter\GrpcTransportAdapterInterface;
use Pulsar\Extension\Grpc\Adapter\RoadRunnerGrpcAdapter;
use Pulsar\Extension\Grpc\Command\GenerateCommand;
use Pulsar\Extension\Grpc\Command\ServeCommand;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Health\HealthService;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Reflection\ReflectionGuard;
use Pulsar\Extension\Grpc\Reflection\ReflectionService;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;
use Pulsar\Extension\Grpc\Server\GrpcServer;
use Pulsar\Extension\Grpc\Server\GrpcServerInterface;
use Pulsar\Extension\Grpc\Server\ServiceRegistry;
use Pulsar\Extension\Grpc\Server\ServiceRegistryInterface;
use Pulsar\Runtime\RuntimeType;
use RuntimeException;

use function file_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Wires gRPC services into the container.
 *
 * Detects the runtime type and refuses to register when running under
 * PHP-FPM, which cannot support gRPC's persistent HTTP/2 connections.
 */
#[Internal(reason: 'gRPC service wiring; use GrpcServer, ServiceRegistryInterface for public API')]
final class GrpcServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $this->guardAgainstFpm($container);

        $config = $this->loadConfig($container);
        $container->instance(GrpcConfig::class, $config);

        // Service registry
        $container->bind(
            ServiceRegistryInterface::class,
            static fn(): ServiceRegistry => new ServiceRegistry(),
        );

        // Transport adapter: wired with TLS credentials when enabled
        $container->bind(
            GrpcTransportAdapterInterface::class,
            static function () use ($config): GrpcTransportAdapterInterface {
                if ($config->adapter === 'roadrunner') {
                    return new RoadRunnerGrpcAdapter(
                        trustedProxy: $config->tls->isMutual(),
                    );
                }

                if ($config->tls->enabled) {
                    self::validateTlsPaths($config);

                    $certChain = self::readFileContents($config->tls->certPath);
                    $privateKey = self::readFileContents($config->tls->keyPath);
                    $rootCert = $config->tls->caPath !== ''
                        ? self::readFileContents($config->tls->caPath)
                        : '';

                    return new GrpcExtensionAdapter($certChain, $privateKey, $rootCert);
                }

                return new GrpcExtensionAdapter();
            },
        );

        // mTLS identity mapper: with optional audit logger for unknown cert events
        $container->bind(
            MtlsIdentityMapper::class,
            static function () use ($config, $container): MtlsIdentityMapper {
                $auditLogger = $container->has(AuditLoggerInterface::class)
                    ? $container->get(AuditLoggerInterface::class)
                    : null;

                return new MtlsIdentityMapper($config->identityMap, $auditLogger);
            },
        );

        // Interceptor pipeline
        $container->bind(
            InterceptorPipeline::class,
            static fn() => InterceptorPipeline::fromConfig($config, $container),
        );

        // Health service
        if ($config->health->enabled) {
            $container->bind(
                HealthService::class,
                static fn(): HealthService => new HealthService(),
            );
        }

        // Reflection service with guard: auto-audits when reflection enabled in production
        $container->bind(
            ReflectionGuard::class,
            static function () use ($config, $container): ReflectionGuard {
                $auditLogger = $container->has(AuditLoggerInterface::class)
                    ? $container->get(AuditLoggerInterface::class)
                    : null;

                return new ReflectionGuard($config->reflection, $auditLogger);
            },
        );
        $container->bind(
            ReflectionService::class,
            static function () use ($container): ReflectionService {
                /** @var ServiceRegistryInterface $registry */
                $registry = $container->get(ServiceRegistryInterface::class);

                return new ReflectionService($registry);
            },
        );

        // GrpcServer
        $container->bind(
            GrpcServerInterface::class,
            static function () use ($container, $config): GrpcServer {
                /** @var ServiceRegistryInterface $registry */
                $registry = $container->get(ServiceRegistryInterface::class);

                /** @var GrpcTransportAdapterInterface $adapter */
                $adapter = $container->get(GrpcTransportAdapterInterface::class);

                /** @var InterceptorPipeline $pipeline */
                $pipeline = $container->get(InterceptorPipeline::class);

                return new GrpcServer($config, $registry, $adapter, $pipeline);
            },
        );

        // CLI commands
        $container->bind(
            ServeCommand::class,
            static function () use ($container): ServeCommand {
                /** @var GrpcServerInterface $server */
                $server = $container->get(GrpcServerInterface::class);

                /** @var GrpcConfig $config */
                $config = $container->get(GrpcConfig::class);

                return new ServeCommand($server, $config);
            },
        );

        $container->bind(
            GenerateCommand::class,
            static function () use ($container): GenerateCommand {
                /** @var GrpcConfig $config */
                $config = $container->get(GrpcConfig::class);

                return new GenerateCommand($config->codegen);
            },
        );
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            GrpcConfig::class,
            ServiceRegistryInterface::class,
            GrpcTransportAdapterInterface::class,
            MtlsIdentityMapper::class,
            InterceptorPipeline::class,
            HealthService::class,
            ReflectionGuard::class,
            ReflectionService::class,
            GrpcServerInterface::class,
            ServeCommand::class,
            GenerateCommand::class,
        ];
    }

    private function loadConfig(ContainerInterface $container): GrpcConfig
    {
        if ($container->has('config.grpc')) {
            /** @var mixed $raw */
            $raw = $container->get('config.grpc');

            if (is_array($raw)) {
                /** @var array<string, mixed> $raw */
                return GrpcConfig::fromArray($raw);
            }
        }

        return new GrpcConfig();
    }

    private static function validateTlsPaths(GrpcConfig $config): void
    {
        if ($config->tls->certPath === '' || !file_exists($config->tls->certPath)) {
            throw new RuntimeException(sprintf(
                'gRPC TLS is enabled but the certificate path is invalid: "%s". '
                . 'Provide a valid path to the PEM-encoded server certificate via tls.cert_path.',
                $config->tls->certPath,
            ));
        }

        if ($config->tls->keyPath === '' || !file_exists($config->tls->keyPath)) {
            throw new RuntimeException(sprintf(
                'gRPC TLS is enabled but the private key path is invalid: "%s". '
                . 'Provide a valid path to the PEM-encoded server private key via tls.key_path.',
                $config->tls->keyPath,
            ));
        }

        if ($config->tls->caPath !== '' && !file_exists($config->tls->caPath)) {
            throw new RuntimeException(sprintf(
                'gRPC mTLS CA certificate path is invalid: "%s". '
                . 'Provide a valid path to the PEM-encoded CA certificate via tls.ca_path.',
                $config->tls->caPath,
            ));
        }
    }

    private static function readFileContents(string $path): string
    {
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new RuntimeException(sprintf(
                'Failed to read TLS file: %s',
                $path,
            ));
        }

        return $contents;
    }

    private function guardAgainstFpm(ContainerInterface $container): void
    {
        if (!$container->has('runtime.type')) {
            return;
        }

        /** @var mixed $type */
        $type = $container->get('runtime.type');

        if ($type instanceof RuntimeType && $type === RuntimeType::Fpm) {
            throw new RuntimeException(
                'The gRPC extension requires a persistent runtime (RoadRunner or FrankenPHP). '
                . 'PHP-FPM cannot maintain the HTTP/2 connections required by gRPC. '
                . 'Configure your application to use a persistent runtime: '
                . 'set RUNTIME_DRIVER=roadrunner or RUNTIME_DRIVER=frankenphp in your environment.',
            );
        }
    }
}
