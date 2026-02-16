<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpRequestPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpResponsePayload;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Tracing\TraceId;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use Throwable;

use function bin2hex;
use function is_int;
use function is_string;
use function microtime;
use function str_contains;
use function strlen;
use function substr;

/**
 * HTTP middleware collector for Studio.
 *
 * Creates a CorrelationContext per request, enters a fiber-local scope,
 * and emits HttpRequest/HttpResponse events. Does NOT resolve tenant --
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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
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

            $response = $handler->handle($request);

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

    private function emitRequest(ServerRequestInterface $request, CorrelationContext $context): void
    {
        /** @var array<string, string> $headers */
        $headers = array_map(static fn(array $values): string => $values[0] ?? '', $request->getHeaders());

        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $routeName = $request->getAttribute('_route_name');

        $bodyContents = (string) $request->getBody();
        $bodyPreview = $bodyContents !== '' ? substr($bodyContents, 0, 2048) : null;

        $uri = $request->getUri();
        $contentLength = $request->getHeaderLine('Content-Length');

        $event = new HttpRequestPayload(
            method: $request->getMethod(),
            uri: (string) $uri,
            path: $uri->getPath(),
            headers: $headers,
            clientIp: is_string($remoteAddr) ? $remoteAddr : null,
            userAgent: $request->getHeaderLine('User-Agent') !== '' ? $request->getHeaderLine('User-Agent') : null,
            contentType: $request->getHeaderLine('Content-Type') !== '' ? $request->getHeaderLine('Content-Type') : null,
            contentLength: $contentLength !== '' ? (int) $contentLength : null,
            routeName: is_string($routeName) ? $routeName : null,
            queryString: $uri->getQuery() !== '' ? $uri->getQuery() : null,
            bodyPreview: $bodyPreview,
        );

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }

    private function emitResponse(?ResponseInterface $response, ServerRequestInterface $request, float $durationMs, CorrelationContext $context): void
    {
        $statusCode = $response?->getStatusCode() ?? 500;

        $headers = [];
        if ($response !== null) {
            foreach ($response->getHeaders() as $name => $values) {
                $headers[$name] = $values[0] ?? '';
            }
        }

        $body = (string) $response?->getBody();

        $responseRouteName = $request->getAttribute('_route_name');
        $contentType = $response?->getHeaderLine('Content-Type') ?: null;

        $responseBodyPreview = null;
        if ($body !== '' && $contentType !== null && $this->isTextualContentType($contentType)) {
            $responseBodyPreview = substr($body, 0, 1024);
        }

        /** @var array<string, string> $headers */
        $event = new HttpResponsePayload(
            statusCode: $statusCode,
            durationMs: $durationMs,
            headers: $headers,
            contentLength: $body !== '' ? strlen($body) : null,
            contentType: $contentType,
            routeName: is_string($responseRouteName) ? $responseRouteName : null,
            bodyPreview: $responseBodyPreview,
        );

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }

    private function extractTraceId(ServerRequestInterface $request): ?string
    {
        $traceContext = $request->getAttribute('_trace_context');

        if ($traceContext instanceof TraceId) {
            return $traceContext->value;
        }

        return null;
    }

    private function isTextualContentType(string $contentType): bool
    {
        return str_contains($contentType, 'text/')
            || str_contains($contentType, 'application/json')
            || str_contains($contentType, 'application/xml')
            || str_contains($contentType, '+json')
            || str_contains($contentType, '+xml');
    }

    private function extractSpanId(ServerRequestInterface $request): ?string
    {
        $spanId = $request->getAttribute('_span_id');

        if (is_string($spanId)) {
            return $spanId;
        }

        if (is_int($spanId)) {
            return (string) $spanId;
        }

        return null;
    }
}
