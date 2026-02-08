<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use function bin2hex;

use Closure;

use function is_int;
use function is_string;
use function microtime;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Observability\Tracing\TraceId;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpRequestPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpResponsePayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function strlen;

use Throwable;

/**
 * HTTP middleware collector for Studio.
 *
 * Creates a CorrelationContext per request, enters a fiber-local scope,
 * and emits HttpRequest/HttpResponse events. Does NOT resolve tenant —
 * that happens in StudioManager::ingest().
 */
#[Internal]
final class HttpCollector implements MiddlewareInterface, CollectorInterface
{
    public bool $enabled = true;

    private readonly Randomizer $randomizer;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly FiberScopedContextProvider $contextProvider,
        private readonly Closure $emit,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * @throws RandomException
     * @throws Throwable
     */
    #[Override]
    public function process(Request $request, callable $next): Response
    {
        if (!$this->enabled) {
            return $next($request);
        }

        $context = new CorrelationContext(
            requestId: bin2hex($this->randomizer->getBytes(16)),
            traceId: $this->extractTraceId($request),
            spanId: $this->extractSpanId($request),
        );

        $request = $request->withAttribute('_studio_correlation', $context);

        $scope = $this->contextProvider->enter($context);
        $startTime = microtime(true);

        try {
            $this->emitRequest($request, $context);

            $response = $next($request);

            $durationMs = (microtime(true) - $startTime) * 1000.0;
            $this->emitResponse($response, $request, $durationMs, $context);

            return $response;
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000.0;
            $this->emitResponse(null, $request, $durationMs, $context);

            throw $e;
        } finally {
            $scope->close();
        }
    }

    private function emitRequest(Request $request, CorrelationContext $context): void
    {
        $headers = array_map(static fn(array $values): string => $values[0] ?? '', $request->headers->toArray());

        $remoteAddr = $request->server('REMOTE_ADDR');
        $routeName = $request->attribute('_route_name');

        $event = new HttpRequestPayload(
            method: $request->method->value,
            uri: $request->uri,
            path: $request->path,
            headers: $headers,
            clientIp: is_string($remoteAddr) ? $remoteAddr : null,
            userAgent: $request->header('User-Agent'),
            contentType: $request->header('Content-Type'),
            contentLength: $request->header('Content-Length') !== null ? (int) $request->header('Content-Length') : null,
            routeName: is_string($routeName) ? $routeName : null,
        );

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }

    private function emitResponse(?Response $response, Request $request, float $durationMs, CorrelationContext $context): void
    {
        $statusCode = $response?->status->value ?? 500;

        $headers = [];
        if ($response !== null) {
            foreach ($response->headers->toArray() as $name => $values) {
                $headers[$name] = $values[0] ?? '';
            }
        }

        $body = $response !== null ? $response->body : '';

        $responseRouteName = $request->attribute('_route_name');

        $event = new HttpResponsePayload(
            statusCode: $statusCode,
            durationMs: $durationMs,
            headers: $headers,
            contentLength: $body !== '' ? strlen($body) : null,
            contentType: $response?->headers->first('Content-Type'),
            routeName: is_string($responseRouteName) ? $responseRouteName : null,
        );

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }

    private function extractTraceId(Request $request): ?string
    {
        $traceContext = $request->attribute('_trace_context');

        if ($traceContext instanceof TraceId) {
            return $traceContext->value;
        }

        return null;
    }

    private function extractSpanId(Request $request): ?string
    {
        $spanId = $request->attribute('_span_id');

        if (is_string($spanId)) {
            return $spanId;
        }

        if (is_int($spanId)) {
            return (string) $spanId;
        }

        return null;
    }
}
