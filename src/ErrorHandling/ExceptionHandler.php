<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use DateMalformedStringException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Observability\ErrorTracking\ErrorAggregatorInterface;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Routing\RoutingException;
use Throwable;

use function sprintf;
use function str_contains;

/**
 * Central exception handler.
 *
 * Resolves HTTP status from exception type, logs every exception
 * with structured context, and renders an error response.
 * Optionally captures errors into the ErrorAggregator for grouping.
 */
final readonly class ExceptionHandler
{
    public function __construct(
        private ExceptionRendererInterface $renderer,
        private ?LoggerInterface $logger = null,
        private ?ErrorAggregatorInterface $errorAggregator = null,
        private ?SensitiveDataScrubber $scrubber = null,
        private ?RequestContextHolder $requestContextHolder = null,
    ) {}

    /**
     * Handle an exception and produce an HTTP response.
     */
    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $status = $this->resolveStatus($exception);
        $headers = $this->resolveHeaders($exception);

        $this->logException($exception, $request, $status);
        $this->captureError($exception, $request);

        // ValidationException always renders as JSON
        if ($exception instanceof ValidationException) {
            return $this->renderValidationJson($exception);
        }

        // Content-negotiation: JSON error for clients that want JSON
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            $response = $this->renderJson($exception, $status, $request);
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            return $response;
        }

        try {
            $body = $this->renderer->render($exception, $request, $status);
        } catch (Throwable) {
            // Fallback: renderer itself failed
            $body = $this->fallbackBody($status);
        }

        $response = Response::html($body, $status->value);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Resolve HTTP status code from exception type.
     */
    private function resolveStatus(Throwable $exception): ResponseStatus
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof RoutingException) {
            if ($exception->isNotFound()) {
                return ResponseStatus::NotFound;
            }

            if ($exception->isMethodNotAllowed()) {
                return ResponseStatus::MethodNotAllowed;
            }
        }

        return ResponseStatus::InternalServerError;
    }

    /**
     * Resolve additional response headers from exception.
     *
     * @return array<string, string>
     */
    private function resolveHeaders(Throwable $exception): array
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getHeaders();
        }

        if ($exception instanceof RoutingException && $exception->isMethodNotAllowed()) {
            return ['Allow' => $exception->getAllowHeader()];
        }

        return [];
    }

    /**
     * Log the exception with structured context.
     */
    private function logException(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'exception' => $exception,
            'status' => $status->value,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
        ];

        $requestContext = $this->resolveRequestContext($request);
        if ($requestContext !== null) {
            $context['correlation_id'] = $requestContext->correlationId->value;
        }

        if ($status->isServerError()) {
            $this->logger->error($exception->getMessage(), $context);
        } else {
            $this->logger->warning($exception->getMessage(), $context);
        }
    }

    /**
     * Capture the error into the aggregator for grouping/tracking.
     */
    private function captureError(Throwable $exception, ServerRequestInterface $request): void
    {
        if ($this->errorAggregator === null) {
            return;
        }

        // Build scrubbed context from request
        $context = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'query' => $request->getQueryParams(),
        ];

        $requestContext = $this->resolveRequestContext($request);
        if ($requestContext !== null) {
            $context['correlation_id'] = $requestContext->correlationId->value;
        }

        if ($this->scrubber !== null) {
            $context = $this->scrubber->scrub($context);
        }

        // Extract trace ID from request attributes if available
        $traceId = null;
        $traceContext = $request->getAttribute('_trace_context');

        if ($traceContext instanceof TraceContext) {
            $traceId = $traceContext->traceId;
        }

        try {
            $event = ErrorEvent::fromThrowable($exception, $context, $traceId);
        } catch (DateMalformedStringException) {
            // Error event creation failure must not disrupt error handling
            return;
        }

        $this->errorAggregator->capture($event);
    }

    /**
     * Render a ValidationException as a 422 JSON response.
     */
    private function renderValidationJson(ValidationException $exception): ResponseInterface
    {
        return Response::validationError($exception->violations());
    }

    /**
     * Render a generic JSON error response for clients that prefer JSON.
     */
    private function renderJson(Throwable $_exception, ResponseStatus $status, ServerRequestInterface $request): ResponseInterface
    {
        $data = [
            'error' => $status->reasonPhrase(),
            'status' => $status->value,
        ];

        $requestContext = $this->resolveRequestContext($request);
        if ($requestContext !== null) {
            $data['correlation_id'] = $requestContext->correlationId->value;
        }

        return Response::json(
            data: $data,
            status: $status->value,
        );
    }

    /**
     * Resolve the current RequestContext from holder or request attribute.
     */
    private function resolveRequestContext(ServerRequestInterface $request): ?RequestContext
    {
        if ($this->requestContextHolder !== null) {
            $context = $this->requestContextHolder->tryGet();
            if ($context !== null) {
                return $context;
            }
        }

        $attribute = $request->getAttribute('_request_context');

        return $attribute instanceof RequestContext ? $attribute : null;
    }

    /**
     * Minimal fallback when the renderer itself fails.
     */
    private function fallbackBody(ResponseStatus $status): string
    {
        return sprintf(
            '<h1>%d %s</h1>',
            $status->value,
            htmlspecialchars($status->reasonPhrase()),
        );
    }
}
