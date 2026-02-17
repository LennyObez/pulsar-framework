<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Edge\EdgeFunctionInterface;
use Pulsar\Edge\EdgeFunctionPipeline;
use Pulsar\Edge\EdgeRequest;
use Pulsar\Edge\EdgeResponse;

#[CoversClass(EdgeFunctionPipeline::class)]
final class EdgeFunctionPipelineTest extends TestCase
{
    #[Test]
    public function returns_null_with_no_functions(): void
    {
        $pipeline = new EdgeFunctionPipeline();
        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($pipeline->process($request));
    }

    #[Test]
    public function returns_first_non_null_response(): void
    {
        $passThrough = $this->createStub(EdgeFunctionInterface::class);
        $passThrough->method('handle')->willReturn(null);
        $passThrough->method('name')->willReturn('pass');

        $blocker = $this->createStub(EdgeFunctionInterface::class);
        $blocker->method('handle')->willReturn(EdgeResponse::deny('blocked'));
        $blocker->method('name')->willReturn('blocker');

        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add($passThrough);
        $pipeline->add($blocker);

        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');
        $response = $pipeline->process($request);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('blocked', $response->body);
    }

    #[Test]
    public function stops_at_first_response(): void
    {
        $first = $this->createStub(EdgeFunctionInterface::class);
        $first->method('handle')->willReturn(EdgeResponse::redirect('/new'));
        $first->method('name')->willReturn('first');

        $second = $this->createMock(EdgeFunctionInterface::class);
        $second->expects(self::never())->method('handle');
        $second->method('name')->willReturn('second');

        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add($first);
        $pipeline->add($second);

        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');
        $response = $pipeline->process($request);

        self::assertNotNull($response);
        self::assertSame(302, $response->statusCode);
    }

    #[Test]
    public function returns_null_when_all_pass_through(): void
    {
        $fn1 = $this->createStub(EdgeFunctionInterface::class);
        $fn1->method('handle')->willReturn(null);
        $fn1->method('name')->willReturn('fn1');

        $fn2 = $this->createStub(EdgeFunctionInterface::class);
        $fn2->method('handle')->willReturn(null);
        $fn2->method('name')->willReturn('fn2');

        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add($fn1);
        $pipeline->add($fn2);

        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($pipeline->process($request));
    }

    #[Test]
    public function function_names_returns_registered_names(): void
    {
        $fn1 = $this->createStub(EdgeFunctionInterface::class);
        $fn1->method('name')->willReturn('geo-routing');

        $fn2 = $this->createStub(EdgeFunctionInterface::class);
        $fn2->method('name')->willReturn('ab-test');

        $pipeline = new EdgeFunctionPipeline();
        $pipeline->add($fn1);
        $pipeline->add($fn2);

        self::assertSame(['geo-routing', 'ab-test'], $pipeline->functionNames());
    }

    #[Test]
    public function function_names_empty_by_default(): void
    {
        $pipeline = new EdgeFunctionPipeline();

        self::assertSame([], $pipeline->functionNames());
    }

    #[Test]
    public function add_returns_self_for_fluent_api(): void
    {
        $fn = $this->createStub(EdgeFunctionInterface::class);
        $fn->method('name')->willReturn('test');

        $pipeline = new EdgeFunctionPipeline();
        $result = $pipeline->add($fn);

        self::assertSame($pipeline, $result);
    }
}
