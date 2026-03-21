<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;
use Pulsar\Observability\Tracing\TraceContextParserInterface;

use function count;

/**
 * Builds and executes the fixed-order interceptor pipeline.
 *
 * Execution order is hardcoded to prevent security bypasses:
 * Tracing -> Auth -> RateLimit -> Validation -> Logging.
 *
 * Individual interceptors may be enabled/disabled via InterceptorToggleConfig,
 * but their relative order cannot change.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InterceptorPipeline
{
    /** @var list<InterceptorInterface> */
    private array $interceptors;

    /**
     * @param list<InterceptorInterface> $interceptors Ordered interceptor list
     */
    public function __construct(array $interceptors = [])
    {
        $this->interceptors = $interceptors;
    }

    /**
     * Create the pipeline from config, resolving dependencies from the container.
     *
     * Only enabled interceptors are included. Interceptors whose required
     * dependencies are missing from the container are silently skipped.
     */
    public static function fromConfig(GrpcConfig $config, ContainerInterface $container): self
    {
        $interceptors = [];
        $toggles = $config->interceptors;

        // Fixed order: Tracing -> Auth -> RateLimit -> Validation -> Logging
        if ($toggles->tracing && $container->has(TraceContextParserInterface::class)) {
            /** @var TraceContextParserInterface $parser */
            $parser = $container->get(TraceContextParserInterface::class);
            $interceptors[] = new TracingInterceptor($parser);
        }

        if ($toggles->auth && $container->has(AuthValidatorInterface::class)) {
            $identityMapper = $container->has(MtlsIdentityMapper::class)
                ? $container->get(MtlsIdentityMapper::class)
                : null;
            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            $interceptors[] = new AuthInterceptor(
                $container->get(AuthValidatorInterface::class),
                $identityMapper,
                $auditLogger,
            );
        }

        if ($toggles->rateLimit) {
            $interceptors[] = new RateLimitInterceptor($config->rateLimit);
        }

        if ($toggles->validation && $container->has(ValidationRuleResolverInterface::class)) {
            $interceptors[] = new ValidationInterceptor(
                $container->get(ValidationRuleResolverInterface::class),
            );
        }

        if ($toggles->logging) {
            $logger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : new NullLogger();

            $interceptors[] = new LoggingInterceptor($logger);
        }

        return new self($interceptors);
    }

    /**
     * Execute the interceptor chain and return the result.
     *
     * Uses iterative indexed dispatch instead of pre-built closure chains
     * to avoid N closure allocations per request.
     *
     * @param Closure(CallContext): InterceptorResult $handler The final handler after all interceptors
     */
    public function process(CallContext $context, Closure $handler): InterceptorResult
    {
        return $this->dispatch($context, $handler, 0);
    }

    /**
     * @return list<InterceptorInterface>
     */
    public function interceptors(): array
    {
        return $this->interceptors;
    }

    public function isEmpty(): bool
    {
        return $this->interceptors === [];
    }

    /**
     * @param Closure(CallContext): InterceptorResult $handler
     */
    private function dispatch(CallContext $context, Closure $handler, int $index): InterceptorResult
    {
        if ($index >= count($this->interceptors)) {
            return $handler($context);
        }

        return $this->interceptors[$index]->handle(
            $context,
            fn(CallContext $ctx): InterceptorResult => $this->dispatch($ctx, $handler, $index + 1),
        );
    }
}
