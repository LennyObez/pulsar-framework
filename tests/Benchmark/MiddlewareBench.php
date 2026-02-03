<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Tests\Benchmark\Support\PassThroughMiddleware;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class MiddlewareBench
{
    private MiddlewarePipeline $pipeline1;
    private MiddlewarePipeline $pipeline5;
    private MiddlewarePipeline $pipeline10;
    private Request $request;

    /** @var callable(Request): Response */
    private $handler;

    public function setUp(): void
    {
        $this->request = new Request(
            method: Method::GET,
            uri: '/benchmark',
            path: '/benchmark',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $this->handler = static fn(Request $request): Response => new Response(body: 'OK');

        $this->pipeline1 = $this->createPipeline(1);
        $this->pipeline5 = $this->createPipeline(5);
        $this->pipeline10 = $this->createPipeline(10);
    }

    private function createPipeline(int $layers): MiddlewarePipeline
    {
        $pipeline = new MiddlewarePipeline();

        for ($i = 0; $i < $layers; $i++) {
            $pipeline->pipe(new PassThroughMiddleware());
        }

        return $pipeline;
    }

    /**
     * Single middleware layer overhead.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 microseconds')]
    public function benchPipelineWith1Middleware(): void
    {
        $this->pipeline1->handle($this->request, $this->handler);
    }

    /**
     * Five middleware layers — typical application stack.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 10 microseconds')]
    public function benchPipelineWith5Middleware(): void
    {
        $this->pipeline5->handle($this->request, $this->handler);
    }

    /**
     * Ten middleware layers — heavy stack.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchPipelineWith10Middleware(): void
    {
        $this->pipeline10->handle($this->request, $this->handler);
    }

    /**
     * Empty pipeline (handler-only baseline).
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchEmptyPipeline(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->handle($this->request, $this->handler);
    }
}
