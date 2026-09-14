<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\PersistentRuntime;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\RuntimeStatus;

use function str_contains;

/**
 * The persistent runtime refuses to interleave requests it cannot isolate.
 *
 * `RequestSandbox` separates one request from the NEXT one on the worker, not
 * from a CONCURRENT one. With more than one connection fiber the two overlap —
 * `CooperativeSleep` suspends a fiber from inside `kernel->handle()` whenever a
 * cache lock or stampede poll is contended — and process-global state the
 * suspended request still holds (the request-scoped container pool, the session
 * manager, the feature flag evaluation log) is reset and re-read by the other
 * request. {@see \Pulsar\Tests\Integration\Runtime\Fiber\InterleavedRequestStateLeakTest}
 * observes that leak happening on the real scheduler; this suite proves the
 * configuration that would reach it cannot be started.
 */
#[CoversClass(PersistentRuntime::class)]
#[CoversClass(RuntimeException::class)]
#[RequiresPhpExtension('sockets')]
final class PersistentRuntimeFiberConcurrencyTest extends TestCase
{
    private function createRuntime(int $fiberConcurrency): PersistentRuntime
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        return new PersistentRuntime(
            kernel: $kernel,
            sandbox: new RequestSandbox($container, new RequestResetRegistry(), new LeakDetector()),
            config: new RuntimeConfig(fiberConcurrency: $fiberConcurrency),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsafeConcurrencies(): iterable
    {
        yield 'two fibers' => [2];
        yield 'the documented four' => [4];
        yield 'the documented sixty-four' => [64];
    }

    #[Test]
    #[DataProvider('unsafeConcurrencies')]
    public function moreThanOneConnectionFiberIsRefused(int $fiberConcurrency): void
    {
        $this->expectException(RuntimeException::class);

        $this->createRuntime($fiberConcurrency);
    }

    #[Test]
    public function theRefusalNamesTheStateThatWouldCross(): void
    {
        try {
            $this->createRuntime(64);
            self::fail('fiber_concurrency=64 must be refused');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        // An operator who reads "concurrency: 64" in a config has no way to
        // discover the leak on their own, so the refusal has to spell it out:
        // which value was refused, what crosses, and what to do instead.
        self::assertStringContainsString('fiber_concurrency=64', $message);
        self::assertStringContainsString('CooperativeSleep', $message);
        self::assertStringContainsString('ScopeManager', $message);
        self::assertStringContainsString('SessionManager', $message);
        self::assertTrue(
            str_contains($message, 'worker processes'),
            'the refusal must name the supported way to scale: ' . $message,
        );
    }

    #[Test]
    public function theSynchronousAcceptLoopIsStillAccepted(): void
    {
        $runtime = $this->createRuntime(0);

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function aSingleConnectionFiberIsStillAccepted(): void
    {
        // One fiber never interleaves with another: FiberScheduler::hasCapacity()
        // refuses to spawn a second, so the accept loop cannot admit a second
        // request while the first is suspended.
        $runtime = $this->createRuntime(1);

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }
}
