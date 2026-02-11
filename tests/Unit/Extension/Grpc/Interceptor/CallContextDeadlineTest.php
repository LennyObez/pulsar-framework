<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;

#[CoversClass(CallContext::class)]
final class CallContextDeadlineTest extends TestCase
{
    private function makeMethod(): MethodDescriptor
    {
        return new MethodDescriptor(
            name: 'Test',
            fullName: '/test/Test',
            type: MethodType::Unary,
            inputType: 'Req',
            outputType: 'Res',
            handler: 'Handler::test',
        );
    }

    #[Test]
    public function isDeadlineExceededReturnsFalseWhenNoDeadline(): void
    {
        $ctx = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            deadline: null,
        );

        self::assertFalse($ctx->isDeadlineExceeded());
    }

    #[Test]
    public function isDeadlineExceededReturnsTrueWhenPast(): void
    {
        $ctx = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            deadline: 1.0, // far in the past
        );

        self::assertTrue($ctx->isDeadlineExceeded());
    }

    #[Test]
    public function isDeadlineExceededReturnsFalseWhenFuture(): void
    {
        $ctx = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            deadline: microtime(true) + 3600.0, // 1 hour from now
        );

        self::assertFalse($ctx->isDeadlineExceeded());
    }

    #[Test]
    public function withAttributePreservesExistingAttributes(): void
    {
        $ctx = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            attributes: ['existing' => 'value'],
        );

        $ctx2 = $ctx->withAttribute('new_key', 'new_value');

        self::assertSame('value', $ctx2->attributes['existing']);
        self::assertSame('new_value', $ctx2->attributes['new_key']);
    }

    #[Test]
    public function getMetadataValueReturnsNullForEmptyList(): void
    {
        $ctx = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            metadata: ['key' => []],
        );

        self::assertNull($ctx->getMetadataValue('key'));
    }
}
