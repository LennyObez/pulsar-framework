<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Security\Audit\AuditLogger;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * Creates middleware pipelines from the bench-pipeline manifest.
 *
 * Builds Tier A pipelines with all in-memory stubs.
 */
final class BenchmarkPipelineFactory
{
    /**
     * Test audit key (32 bytes hex-encoded for benchmarks only).
     */
    public const string BENCH_AUDIT_KEY = '0123456789abcdef0123456789abcdef';

    /** @var array<string, mixed> */
    private readonly array $manifest;
    private readonly StubAuthManager $authManager;
    private readonly InMemoryAuditSink $auditSink;
    private readonly AuditLogger $auditLogger;
    private readonly NullComplianceDispatcher $complianceDispatcher;
    private readonly StubTokenResolver $tokenResolver;
    private readonly GateInterface $gate;

    public function __construct()
    {
        /** @var array<string, mixed> $manifest */
        $manifest = require __DIR__ . '/../../../tools/php/bench-pipeline.manifest.php';
        $this->manifest = $manifest;

        $identity = new Identity(
            id: 'bench-user-1',
            displayName: 'Benchmark User',
            roles: ['admin'],
        );

        $this->authManager = new StubAuthManager($identity);
        $this->auditSink = new InMemoryAuditSink();
        $this->auditLogger = new AuditLogger(
            sink: $this->auditSink,
            auditKey: self::BENCH_AUDIT_KEY,
        );
        $this->complianceDispatcher = new NullComplianceDispatcher();
        $this->tokenResolver = new StubTokenResolver($identity);

        $registry = new InMemoryRoleRegistry();
        $registry->register(new Role('admin', [
            new Permission('api.request'),
            new Permission('data.read'),
        ]));

        $this->gate = new Gate($registry, []);
    }

    /**
     * Build a middleware pipeline for the given request class.
     */
    public function createPipeline(string $requestClass): MiddlewarePipeline
    {
        /** @var array<string, array<string, mixed>> $requestClasses */
        $requestClasses = $this->manifest['request_classes'];

        if (!array_key_exists($requestClass, $requestClasses)) {
            throw new RuntimeException(sprintf(
                'Unknown request class: %s. Available: %s',
                $requestClass,
                implode(', ', array_keys($requestClasses)),
            ));
        }

        /** @var list<string> $middlewareIds */
        $middlewareIds = $requestClasses[$requestClass]['middleware'];

        $pipeline = new MiddlewarePipeline();

        foreach ($middlewareIds as $middlewareId) {
            $pipeline->pipe($this->resolveMiddleware($middlewareId));
        }

        return $pipeline;
    }

    /**
     * Create a request handler that returns a JSON response.
     */
    public function createHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::json(['status' => 'ok', 'data' => ['id' => 1, 'name' => 'benchmark']]);
            }
        };
    }

    public function auditSink(): InMemoryAuditSink
    {
        return $this->auditSink;
    }

    public function auditLogger(): AuditLogger
    {
        return $this->auditLogger;
    }

    public function complianceDispatcher(): NullComplianceDispatcher
    {
        return $this->complianceDispatcher;
    }

    private function resolveMiddleware(string $middlewareId): MiddlewareInterface
    {
        return match ($middlewareId) {
            'error-handler' => new BenchErrorHandlerMiddleware(),
            'routing' => new BenchRoutingMiddleware(),
            'session-start' => new BenchSessionMiddleware(),
            'auth-guard' => new AuthenticationMiddleware($this->authManager),
            'authorization' => new AuthorizationMiddleware($this->gate, $this->auditLogger),
            'token-resolver' => new BenchTokenResolverMiddleware($this->tokenResolver, $this->authManager),
            'audit-writer' => new BenchAuditWriterMiddleware($this->auditLogger),
            'compliance-dispatcher' => new BenchComplianceDispatcherMiddleware($this->complianceDispatcher),
            'content-negotiation' => new BenchContentNegotiationMiddleware(),
            default => throw new RuntimeException(sprintf('Unknown middleware ID: %s', $middlewareId)),
        };
    }
}
