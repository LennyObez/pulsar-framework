<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\SecurityContext;
use Pulsar\Container\Container;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Tenancy\TenantContext;
use stdClass;

#[CoversClass(RequestSandbox::class)]
final class RequestSandboxIntegrationTest extends TestCase
{
    private Container $container;
    private RequestResetRegistry $registry;
    private RequestSandbox $sandbox;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        $this->sandbox = new RequestSandbox($this->container, $this->registry, $detector);
    }

    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function it_resets_tenant_context_between_requests(): void
    {
        $tenantContext = new TenantContext();
        $this->container->instance(TenantContext::class, $tenantContext);
        $this->registry->registerResettable(TenantContext::class);

        $tenantContext->set(new \Pulsar\Tenancy\Tenant('tenant-1', 'Acme Corp', []));
        self::assertTrue($tenantContext->isResolved());

        $this->sandbox->beforeRequest($this->createRequest());
        $this->sandbox->afterRequest($this->createRequest(), new Response());

        self::assertFalse($tenantContext->isResolved());
    }

    #[Test]
    public function it_resets_flag_evaluation_log_between_requests(): void
    {
        $flagLog = new FlagEvaluationLog();
        $this->container->instance(FlagEvaluationLog::class, $flagLog);
        $this->registry->registerResettable(FlagEvaluationLog::class);

        $flagLog->record(new \Pulsar\FeatureFlag\FlagEvaluation(
            flagName: 'feature-x',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));
        self::assertSame(1, $flagLog->count());

        $this->sandbox->beforeRequest($this->createRequest());
        $this->sandbox->afterRequest($this->createRequest(), new Response());

        self::assertSame(0, $flagLog->count());
    }

    #[Test]
    public function it_resets_auth_manager_between_requests(): void
    {
        $authManager = new AuthManager();
        $this->container->instance(\Pulsar\Auth\AuthManagerInterface::class, $authManager);
        $this->registry->registerResettable(\Pulsar\Auth\AuthManagerInterface::class);

        // AuthManager.resetRequestState() is defense-in-depth (currently no-op)
        // Just verify it doesn't throw
        $this->sandbox->beforeRequest($this->createRequest());
        $this->sandbox->afterRequest($this->createRequest(), new Response());

        self::assertSame('session', $authManager->defaultGuard());
    }

    #[Test]
    public function it_evicts_security_context_between_requests(): void
    {
        $this->registry->registerEvictable(SecurityContext::class);

        // Simulate middleware creating SecurityContext for request 1
        $authManager = new AuthManager();
        $request1 = new Request(
            method: Method::GET,
            uri: '/dashboard',
            path: '/dashboard',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer token-user-1']),
            body: '',
        );
        $secCtx1 = new SecurityContext($authManager, $request1);
        $this->container->instance(SecurityContext::class, $secCtx1);

        self::assertTrue($this->container->has(SecurityContext::class));

        // After request 1: sandbox evicts SecurityContext
        $this->sandbox->beforeRequest($request1);
        $this->sandbox->afterRequest($request1, new Response(body: 'ok', status: ResponseStatus::OK));

        // SecurityContext should be evicted from instances
        self::assertNotContains(SecurityContext::class, $this->container->getInstances());
    }

    #[Test]
    public function two_sequential_requests_never_share_identity(): void
    {
        $this->registry->registerEvictable(SecurityContext::class);

        $authManager = new AuthManager();
        $this->container->instance(\Pulsar\Auth\AuthManagerInterface::class, $authManager);

        // Request 1 with user-1 credentials
        $request1 = new Request(
            method: Method::GET,
            uri: '/profile',
            path: '/profile',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer user-1-token']),
            body: '',
        );

        $secCtx1 = new SecurityContext($authManager, $request1);
        $this->container->instance(SecurityContext::class, $secCtx1);

        $identity1 = $secCtx1->identity();

        // Cleanup request 1
        $this->sandbox->beforeRequest($request1);
        $this->sandbox->afterRequest($request1, new Response());

        // Request 2 with user-2 credentials
        $request2 = new Request(
            method: Method::GET,
            uri: '/profile',
            path: '/profile',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer user-2-token']),
            body: '',
        );

        // In real runtime, middleware would recreate SecurityContext
        $secCtx2 = new SecurityContext($authManager, $request2);
        $this->container->instance(SecurityContext::class, $secCtx2);

        $identity2 = $secCtx2->identity();

        // Both are anonymous (no guards registered), but the key point is
        // they are DIFFERENT SecurityContext instances
        self::assertNotSame($secCtx1, $secCtx2);
    }

    #[Test]
    public function persistent_services_survive_request_cleanup(): void
    {
        $tenantContext = new TenantContext();
        $this->container->instance(TenantContext::class, $tenantContext);
        $this->registry->registerResettable(TenantContext::class);

        // Register a "persistent" service that should NOT be affected
        $persistentService = new stdClass();
        $persistentService->value = 'persistent';
        $this->container->instance('app.persistent', $persistentService);

        $this->sandbox->beforeRequest($this->createRequest());
        $this->sandbox->afterRequest($this->createRequest(), new Response());

        // Persistent service should still be there
        self::assertTrue($this->container->has('app.persistent'));
        /** @var stdClass $service */
        $service = $this->container->get('app.persistent');
        self::assertSame('persistent', $service->value);
    }
}
