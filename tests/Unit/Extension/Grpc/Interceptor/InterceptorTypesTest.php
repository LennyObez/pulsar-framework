<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;

#[CoversClass(CallContext::class)]
#[CoversClass(InterceptorResult::class)]
final class InterceptorTypesTest extends TestCase
{
    private function makeMethod(): MethodDescriptor
    {
        return new MethodDescriptor(
            name: 'GetPatient',
            fullName: '/health.v1.PatientService/GetPatient',
            type: MethodType::Unary,
            inputType: 'health.v1.GetPatientRequest',
            outputType: 'health.v1.PatientResponse',
            handler: 'App\\Grpc\\PatientHandler::get',
        );
    }

    // --- CallContext ---

    #[Test]
    public function callContextConstruction(): void
    {
        $method = $this->makeMethod();
        $ctx = new CallContext(
            method: $method,
            payload: "\x0a\x05hello",
            metadata: ['authorization' => ['Bearer jwt-token-abc123']],
            deadline: 1700000000.123,
            peerIdentity: 'patient-service.internal.example.com',
        );

        self::assertSame($method, $ctx->method);
        self::assertSame("\x0a\x05hello", $ctx->payload);
        self::assertSame(['Bearer jwt-token-abc123'], $ctx->metadata['authorization']);
        self::assertSame(1700000000.123, $ctx->deadline);
        self::assertSame('patient-service.internal.example.com', $ctx->peerIdentity);
        self::assertSame([], $ctx->attributes);
    }

    #[Test]
    public function callContextWithAttributeReturnsNewInstance(): void
    {
        $ctx = new CallContext(method: $this->makeMethod(), payload: '');
        $ctx2 = $ctx->withAttribute('user_id', 'usr-550e8400');

        self::assertSame([], $ctx->attributes);
        self::assertSame('usr-550e8400', $ctx2->attributes['user_id']);
    }

    #[Test]
    public function callContextWithMetadataReturnsNewInstance(): void
    {
        $ctx = new CallContext(method: $this->makeMethod(), payload: '');
        $ctx2 = $ctx->withMetadata(['x-request-id' => ['req-abc123']]);

        self::assertSame([], $ctx->metadata);
        self::assertSame(['req-abc123'], $ctx2->metadata['x-request-id']);
    }

    #[Test]
    public function callContextGetMetadataValue(): void
    {
        $ctx = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            metadata: ['authorization' => ['Bearer token-xyz']],
        );

        self::assertSame('Bearer token-xyz', $ctx->getMetadataValue('authorization'));
        self::assertNull($ctx->getMetadataValue('nonexistent'));
    }

    // --- InterceptorResult ---

    #[Test]
    public function resultDefaults(): void
    {
        $result = new InterceptorResult();

        self::assertSame('', $result->payload);
        self::assertSame(GrpcStatus::Ok, $result->status);
        self::assertSame('', $result->message);
        self::assertSame([], $result->trailers);
    }

    #[Test]
    public function resultOkFactory(): void
    {
        $result = InterceptorResult::ok("\x0a\x05world");

        self::assertSame("\x0a\x05world", $result->payload);
        self::assertTrue($result->isOk());
        self::assertSame(GrpcStatus::Ok, $result->status);
    }

    #[Test]
    public function resultOkWithTrailers(): void
    {
        $result = InterceptorResult::ok('data', ['x-trace-id' => ['trace-123']]);

        self::assertSame(['trace-123'], $result->trailers['x-trace-id']);
    }

    #[Test]
    public function resultErrorFactory(): void
    {
        $result = InterceptorResult::error(GrpcStatus::PermissionDenied, 'Access denied');

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::PermissionDenied, $result->status);
        self::assertSame('Access denied', $result->message);
    }

    #[Test]
    public function resultIsOk(): void
    {
        self::assertTrue(InterceptorResult::ok('')->isOk());
        self::assertFalse(InterceptorResult::error(GrpcStatus::Internal)->isOk());
    }
}
