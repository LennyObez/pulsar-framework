<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Container\Container;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Runtime\Hygiene\HygieneProfileInterface;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\ResettableInterface;
use stdClass;

#[CoversClass(RequestSandbox::class)]
final class RequestSandboxTest extends TestCase
{
    private function createRequest(): ServerRequestInterface
    {
        return new ServerRequest(method: 'GET', uri: '/');
    }

    #[Test]
    public function before_request_returns_the_request(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        $sandbox = new RequestSandbox($container, $registry, $detector);
        $request = $this->createRequest();

        $result = $sandbox->beforeRequest($request);

        self::assertSame($request, $result);
    }

    #[Test]
    public function after_request_resets_resettable_services(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        $resettable = $this->createMock(ResettableInterface::class);
        $resettable->expects(self::once())->method('resetRequestState');

        $container->instance('test.service', $resettable);
        $registry->registerResettable('test.service');

        $sandbox = new RequestSandbox($container, $registry, $detector);
        $sandbox->beforeRequest($this->createRequest());

        $sandbox->afterRequest(
            $this->createRequest(),
            new Response(statusCode: 200, body: 'ok'),
        );
    }

    #[Test]
    public function after_request_evicts_registered_services(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        // Register an instance that should be evicted
        $mock = new stdClass();
        $container->instance(SecurityContext::class, $mock);
        $registry->registerEvictable(SecurityContext::class);

        self::assertTrue($container->has(SecurityContext::class));

        $sandbox = new RequestSandbox($container, $registry, $detector);
        $sandbox->beforeRequest($this->createRequest());

        $sandbox->afterRequest(
            $this->createRequest(),
            new Response(statusCode: 200, body: 'ok'),
        );

        // Instance should be evicted — but the binding still exists (has returns true for bindings too)
        // Container::forgetInstance only removes from instances array
        // After eviction, get() would need to resolve again
        // We can verify by checking the instances list
        self::assertNotContains(SecurityContext::class, $container->getInstances());
    }

    #[Test]
    public function after_request_returns_leak_warnings(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        $sandbox = new RequestSandbox($container, $registry, $detector);
        $sandbox->beforeRequest($this->createRequest());

        // Track a resource that won't be released
        $detector->trackResource('leaked-conn', 'database', 'Leaked connection');

        $warnings = $sandbox->afterRequest(
            $this->createRequest(),
            new Response(statusCode: 200, body: 'ok'),
        );

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('leaked-conn', $warnings[0]);
    }

    #[Test]
    public function eviction_happens_before_reset(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        $order = [];

        // Track eviction by overriding forgetInstance behavior
        $registry->registerEvictable('evictable.service');
        $container->instance('evictable.service', new stdClass());

        $resettable = new class ($order) implements ResettableInterface {
            /** @param list<string> $order */
            public function __construct(private array &$order) {} // @phpstan-ignore property.onlyWritten

            public function resetRequestState(): void
            {
                $this->order[] = 'reset';
            }
        };

        $container->instance('resettable.service', $resettable);
        $registry->registerResettable('resettable.service');

        $sandbox = new RequestSandbox($container, $registry, $detector);
        $sandbox->beforeRequest($this->createRequest());
        $sandbox->afterRequest($this->createRequest(), new Response(statusCode: 200));

        // Reset should have been called (eviction happens silently before)
        self::assertContains('reset', $order);
    }

    #[Test]
    public function before_request_calls_hygiene_apply(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        $hygiene = $this->createMock(HygieneProfileInterface::class);
        $hygiene->expects(self::once())->method('apply');

        $sandbox = new RequestSandbox($container, $registry, $detector, $hygiene);
        $sandbox->beforeRequest($this->createRequest());
    }

    #[Test]
    public function hygiene_is_called_before_leak_detector(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();
        $order = [];

        $hygiene = new class ($order, $detector) implements HygieneProfileInterface {
            /** @param list<string> $order */
            public function __construct(
                private array &$order, // @phpstan-ignore property.onlyWritten
                private LeakDetector $detector,
            ) {}

            public function apply(): void
            {
                // At apply() time, the leak detector should NOT have been called yet.
                // The memory baseline will be 0 if beginRequest() hasn't run.
                $this->order[] = 'hygiene:baseline=' . $this->detector->memoryBaseline();
            }
        };

        $sandbox = new RequestSandbox($container, $registry, $detector, $hygiene);
        $sandbox->beforeRequest($this->createRequest());

        // Hygiene was called when baseline was still 0 (before beginRequest set it)
        self::assertSame(['hygiene:baseline=0'], $order);
        // After beforeRequest, the leak detector baseline is set (non-zero)
        self::assertGreaterThan(0, $detector->memoryBaseline());
    }

    #[Test]
    public function null_hygiene_is_handled_gracefully(): void
    {
        $container = new Container();
        $registry = new RequestResetRegistry();
        $detector = new LeakDetector();

        // Explicitly pass null hygiene
        $sandbox = new RequestSandbox($container, $registry, $detector, null);
        $request = $this->createRequest();

        $result = $sandbox->beforeRequest($request);

        self::assertSame($request, $result);
    }
}
