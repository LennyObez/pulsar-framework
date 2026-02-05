<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Core\Kernel;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Benchmarks for full kernel request dispatch cycle.
 *
 * Covers cold boot + handle (first request) and pre-booted handle (subsequent requests).
 */
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class KernelBench
{
    private Request $request;
    private Kernel $bootedKernel;

    public function setUpRequest(): void
    {
        $this->request = new Request(
            method: Method::GET,
            uri: '/bench',
            path: '/bench',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    public function setUpBootedKernel(): void
    {
        $this->setUpRequest();

        $this->bootedKernel = new Kernel();
        $this->bootedKernel->router()->get('/bench', static fn(): Response => Response::text('ok'));
        $this->bootedKernel->boot();
    }

    #[Subject]
    #[BeforeMethods('setUpRequest')]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchKernelBootAndHandle(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/bench', static fn(): Response => Response::text('ok'));
        $kernel->handle($this->request);
    }

    #[Subject]
    #[BeforeMethods('setUpBootedKernel')]
    #[Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchPreBootedKernelHandle(): void
    {
        $this->bootedKernel->handle($this->request);
    }
}
