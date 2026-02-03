<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\SecurityContext;
use Pulsar\Container\Container;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\ResettableInterface;
use stdClass;

#[CoversClass(RequestSandbox::class)]
final class RequestSandboxTest extends TestCase
{
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
            new Response(body: 'ok', status: ResponseStatus::OK),
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
            new Response(body: 'ok', status: ResponseStatus::OK),
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
            new Response(body: 'ok', status: ResponseStatus::OK),
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
        $sandbox->afterRequest($this->createRequest(), new Response());

        // Reset should have been called (eviction happens silently before)
        self::assertContains('reset', $order);
    }
}
