<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Kernel;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\FpmRuntime;
use Pulsar\Runtime\RuntimeStatus;

#[CoversClass(FpmRuntime::class)]
final class FpmRuntimeTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel();
    }

    #[Test]
    public function it_starts_with_stopped_status(): void
    {
        $runtime = new FpmRuntime($this->kernel);

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function stop_transitions_to_stopped(): void
    {
        $runtime = new FpmRuntime($this->kernel);
        $runtime->stop();

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function before_request_passes_through(): void
    {
        $runtime = new FpmRuntime($this->kernel);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $result = $runtime->beforeRequest($request);
        self::assertSame($request, $result);
    }

    #[Test]
    public function after_request_is_noop(): void
    {
        $runtime = new FpmRuntime($this->kernel);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $response = new Response(body: 'ok', status: ResponseStatus::OK);

        $this->expectNotToPerformAssertions();
        $runtime->afterRequest($request, $response);
    }
}
