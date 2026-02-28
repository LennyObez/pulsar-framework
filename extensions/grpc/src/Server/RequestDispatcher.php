<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Server;

use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Throwable;

/**
 * Dispatches a gRPC request through the interceptor pipeline to the service handler.
 *
 * Receives a CallContext, runs it through the interceptor pipeline, then invokes
 * the resolved service handler. GrpcExceptions are caught and mapped to
 * InterceptorResult with the appropriate status code.
 */
#[Internal(reason: 'Request dispatch orchestration — not part of public API')]
final readonly class RequestDispatcher
{
    public function __construct(
        private InterceptorPipeline $pipeline,
    ) {}

    /**
     * Dispatch a call through the pipeline and into the service handler.
     */
    public function dispatch(CallContext $context, ServiceHandlerInterface $handler): InterceptorResult
    {
        $terminalHandler = static function (CallContext $ctx) use ($handler): InterceptorResult {
            try {
                $responsePayload = $handler->invoke(
                    $ctx->method->name,
                    $ctx->payload,
                );

                return InterceptorResult::ok($responsePayload);
            } catch (GrpcException $e) {
                return InterceptorResult::error($e->status, $e->getMessage());
            }
        };

        try {
            return $this->pipeline->process($context, $terminalHandler);
        } catch (GrpcException $e) {
            return InterceptorResult::error($e->status, $e->getMessage());
        } catch (Throwable) {
            return InterceptorResult::error(
                GrpcStatus::Internal,
                'Internal server error',
            );
        }
    }
}
