<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Config\CodegenConfig;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Config\HealthConfig;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Config\InterceptorToggleConfig;
use Pulsar\Extension\Grpc\Config\RateLimitConfig;
use Pulsar\Extension\Grpc\Config\ReflectionConfig;
use Pulsar\Extension\Grpc\Config\TlsConfig;

#[CoversClass(CodegenConfig::class)]
#[CoversClass(GrpcConfig::class)]
#[CoversClass(HealthConfig::class)]
#[CoversClass(IdentityMappingEntry::class)]
#[CoversClass(InterceptorToggleConfig::class)]
#[CoversClass(RateLimitConfig::class)]
#[CoversClass(ReflectionConfig::class)]
#[CoversClass(TlsConfig::class)]
final class GrpcConfigsTest extends TestCase
{
    // --- GrpcConfig ---

    #[Test]
    public function grpcConfigDefaults(): void
    {
        $config = new GrpcConfig();
        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(50051, $config->port);
        self::assertSame(4, $config->maxWorkers);
        self::assertSame(100, $config->maxConcurrentStreams);
        self::assertSame(60, $config->keepAliveIntervalSeconds);
        self::assertSame(20, $config->keepAliveTimeoutSeconds);
        self::assertSame('grpc_extension', $config->adapter);
        self::assertSame([], $config->identityMap);
    }

    #[Test]
    public function grpcConfigFromArray(): void
    {
        $config = GrpcConfig::fromArray([
            'host' => '127.0.0.1',
            'port' => 9090,
            'max_workers' => 8,
            'adapter' => 'roadrunner',
        ]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(8, $config->maxWorkers);
        self::assertSame('roadrunner', $config->adapter);
    }

    #[Test]
    public function grpcConfigFromArrayWithNestedConfigs(): void
    {
        $config = GrpcConfig::fromArray([
            'tls' => ['enabled' => true, 'cert_path' => '/etc/tls/cert.pem'],
            'interceptors' => ['auth' => false],
            'rate_limit' => ['max_requests_per_second' => 500],
            'reflection' => ['enabled' => true],
            'health' => ['enabled' => false],
            'codegen' => ['proto_path' => 'protos'],
        ]);

        self::assertTrue($config->tls->enabled);
        self::assertSame('/etc/tls/cert.pem', $config->tls->certPath);
        self::assertFalse($config->interceptors->auth);
        self::assertSame(500, $config->rateLimit->maxRequestsPerSecond);
        self::assertTrue($config->reflection->enabled);
        self::assertFalse($config->health->enabled);
        self::assertSame('protos', $config->codegen->protoPath);
    }

    // --- CodegenConfig ---

    #[Test]
    public function codegenConfigDefaults(): void
    {
        $config = new CodegenConfig();
        self::assertSame('proto', $config->protoPath);
        self::assertSame('src/Generated', $config->outputPath);
        self::assertSame('protoc', $config->protocBinary);
        self::assertSame('grpc_php_plugin', $config->grpcPhpPlugin);
    }

    #[Test]
    public function codegenConfigFromArray(): void
    {
        $config = CodegenConfig::fromArray([
            'proto_path' => 'api/proto',
            'output_path' => 'generated/src',
            'protoc_binary' => '/usr/local/bin/protoc',
            'grpc_php_plugin' => '/usr/local/bin/grpc_php_plugin',
        ]);

        self::assertSame('api/proto', $config->protoPath);
        self::assertSame('generated/src', $config->outputPath);
    }

    // --- HealthConfig ---

    #[Test]
    public function healthConfigDefaults(): void
    {
        $config = new HealthConfig();
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function healthConfigFromArray(): void
    {
        $config = HealthConfig::fromArray(['enabled' => false]);
        self::assertFalse($config->enabled);
    }

    // --- IdentityMappingEntry ---

    #[Test]
    public function identityMappingEntryDefaults(): void
    {
        $entry = new IdentityMappingEntry('payment-service');
        self::assertSame('payment-service', $entry->name);
        self::assertSame('internal', $entry->trustLevel);
        self::assertSame(['*'], $entry->allowedMethods);
    }

    #[Test]
    public function identityMappingEntryFromArray(): void
    {
        $entry = IdentityMappingEntry::fromArray([
            'name' => 'audit-service',
            'trust_level' => 'elevated',
            'allowed_methods' => ['/audit.v1.AuditService/LogEvent'],
        ]);

        self::assertSame('audit-service', $entry->name);
        self::assertSame('elevated', $entry->trustLevel);
        self::assertSame(['/audit.v1.AuditService/LogEvent'], $entry->allowedMethods);
    }

    #[Test]
    public function identityMappingEntryWildcardAllowsAllMethods(): void
    {
        $entry = new IdentityMappingEntry('admin-service');
        self::assertTrue($entry->isMethodAllowed('/any.Service/AnyMethod'));
    }

    #[Test]
    public function identityMappingEntryRestrictsToAllowedMethods(): void
    {
        $entry = new IdentityMappingEntry(
            'restricted-service',
            'internal',
            ['/user.v1.UserService/GetUser'],
        );

        self::assertTrue($entry->isMethodAllowed('/user.v1.UserService/GetUser'));
        self::assertFalse($entry->isMethodAllowed('/user.v1.UserService/DeleteUser'));
    }

    // --- InterceptorToggleConfig ---

    #[Test]
    public function interceptorToggleConfigDefaults(): void
    {
        $config = new InterceptorToggleConfig();
        self::assertTrue($config->tracing);
        self::assertTrue($config->auth);
        self::assertTrue($config->rateLimit);
        self::assertTrue($config->validation);
        self::assertTrue($config->logging);
    }

    #[Test]
    public function interceptorToggleConfigFromArray(): void
    {
        $config = InterceptorToggleConfig::fromArray([
            'tracing' => false,
            'auth' => false,
            'rate_limit' => false,
        ]);

        self::assertFalse($config->tracing);
        self::assertFalse($config->auth);
        self::assertFalse($config->rateLimit);
        self::assertTrue($config->validation);
        self::assertTrue($config->logging);
    }

    // --- RateLimitConfig ---

    #[Test]
    public function rateLimitConfigDefaults(): void
    {
        $config = new RateLimitConfig();
        self::assertSame(1000, $config->maxRequestsPerSecond);
        self::assertSame(100, $config->burstSize);
    }

    #[Test]
    public function rateLimitConfigFromArray(): void
    {
        $config = RateLimitConfig::fromArray([
            'max_requests_per_second' => 5000,
            'burst_size' => 250,
        ]);

        self::assertSame(5000, $config->maxRequestsPerSecond);
        self::assertSame(250, $config->burstSize);
    }

    // --- ReflectionConfig ---

    #[Test]
    public function reflectionConfigDefaults(): void
    {
        $config = new ReflectionConfig();
        self::assertFalse($config->enabled);
        self::assertFalse($config->allowInProduction);
    }

    #[Test]
    public function reflectionConfigFromArray(): void
    {
        $config = ReflectionConfig::fromArray([
            'enabled' => true,
            'allow_in_production' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->allowInProduction);
    }

    // --- TlsConfig ---

    #[Test]
    public function tlsConfigDefaults(): void
    {
        $config = new TlsConfig();
        self::assertFalse($config->enabled);
        self::assertSame('', $config->certPath);
        self::assertSame('', $config->keyPath);
        self::assertSame('', $config->caPath);
        self::assertFalse($config->mutual);
    }

    #[Test]
    public function tlsConfigFromArray(): void
    {
        $config = TlsConfig::fromArray([
            'enabled' => true,
            'cert_path' => '/etc/tls/server.crt',
            'key_path' => '/etc/tls/server.key',
            'ca_path' => '/etc/tls/ca.crt',
            'mutual' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('/etc/tls/server.crt', $config->certPath);
        self::assertSame('/etc/tls/server.key', $config->keyPath);
        self::assertSame('/etc/tls/ca.crt', $config->caPath);
        self::assertTrue($config->mutual);
    }

    #[Test]
    public function tlsIsMutualRequiresAllConditions(): void
    {
        self::assertFalse(new TlsConfig()->isMutual());
        self::assertFalse(new TlsConfig(enabled: true, mutual: true)->isMutual());
        self::assertFalse(new TlsConfig(enabled: false, mutual: true, caPath: '/ca.crt')->isMutual());
        self::assertTrue(new TlsConfig(enabled: true, mutual: true, caPath: '/ca.crt')->isMutual());
    }
}
