<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Middleware;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Middleware\JobMiddlewareInterface;
use Pulsar\Queue\Middleware\MiddlewarePipeline;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function processCallsDestinationDirectlyWithNoMiddleware(): void
    {
        $pipeline = new MiddlewarePipeline();
        $envelope = $this->createEnvelope();
        $called = false;

        $pipeline->process($envelope, static function (JobEnvelope $e) use (&$called): string {
            $called = true;

            return 'result';
        });

        self::assertTrue($called);
    }

    #[Test]
    public function processReturnsDestinationResult(): void
    {
        $pipeline = new MiddlewarePipeline();
        $envelope = $this->createEnvelope();

        $result = $pipeline->process($envelope, static fn(JobEnvelope $e): string => 'final');

        self::assertSame('final', $result);
    }

    #[Test]
    public function middlewareExecutesInFifoOrder(): void
    {
        $order = [];

        $middleware1 = $this->createMiddleware(function (JobEnvelope $e, Closure $next) use (&$order): mixed {
            $order[] = 'before-1';
            $result = $next($e);
            $order[] = 'after-1';

            return $result;
        });

        $middleware2 = $this->createMiddleware(function (JobEnvelope $e, Closure $next) use (&$order): mixed {
            $order[] = 'before-2';
            $result = $next($e);
            $order[] = 'after-2';

            return $result;
        });

        $pipeline = new MiddlewarePipeline([$middleware1, $middleware2]);
        $envelope = $this->createEnvelope();

        $pipeline->process($envelope, static function (JobEnvelope $e) use (&$order): string {
            $order[] = 'destination';

            return 'done';
        });

        self::assertSame(['before-1', 'before-2', 'destination', 'after-2', 'after-1'], $order);
    }

    #[Test]
    public function middlewareCanShortCircuit(): void
    {
        $destinationCalled = false;

        $middleware = $this->createMiddleware(
            static fn(JobEnvelope $e, Closure $next): string => 'short-circuited',
        );

        $pipeline = new MiddlewarePipeline([$middleware]);
        $envelope = $this->createEnvelope();

        $result = $pipeline->process($envelope, static function (JobEnvelope $e) use (&$destinationCalled): string {
            $destinationCalled = true;

            return 'should-not-reach';
        });

        self::assertSame('short-circuited', $result);
        self::assertFalse($destinationCalled);
    }

    #[Test]
    public function middlewareCanModifyEnvelope(): void
    {
        $middleware = $this->createMiddleware(
            static fn(JobEnvelope $e, Closure $next): mixed => $next($e->withMetadata(['injected' => true])),
        );

        $pipeline = new MiddlewarePipeline([$middleware]);
        $envelope = $this->createEnvelope();
        $receivedEnvelope = null;

        $pipeline->process($envelope, static function (JobEnvelope $e) use (&$receivedEnvelope): string {
            $receivedEnvelope = $e;

            return 'ok';
        });

        self::assertNotNull($receivedEnvelope);
        self::assertTrue($receivedEnvelope->metadata['injected']);
    }

    private function createMiddleware(Closure $handler): JobMiddlewareInterface
    {
        return new class ($handler) implements JobMiddlewareInterface {
            public function __construct(private readonly Closure $handler) {}

            public function handle(JobEnvelope $envelope, Closure $next): mixed
            {
                return ($this->handler)($envelope, $next);
            }
        };
    }

    private function createEnvelope(): JobEnvelope
    {
        return new JobEnvelope(
            id: 'j1',
            jobClass: 'C',
            payload: '{}',
            queue: 'default',
            idempotencyKey: '',
            correlationId: 'c1',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 1,
            retryBackoffStrategy: BackoffStrategy::Fixed,
            retryDelayMs: 0,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );
    }
}
