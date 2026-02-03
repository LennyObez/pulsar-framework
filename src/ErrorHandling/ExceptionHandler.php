<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Psr\Log\LoggerInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Routing\RoutingException;

use function sprintf;

use Throwable;

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
        private ?ErrorAggregator $errorAggregator = null,
        private ?SensitiveDataScrubber $scrubber = null,
    ) {}

    /**
     * Handle an exception and produce an HTTP response.
     */
    public function handle(Throwable $exception, Request $request): Response
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
        if ($request->wantsJson()) {
            $response = $this->renderJson($exception, $status);
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

        $response = Response::html($body, $status);

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
    private function logException(Throwable $exception, Request $request, ResponseStatus $status): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'exception' => $exception,
            'status' => $status->value,
            'method' => $request->method->value,
            'uri' => $request->uri,
        ];

        if ($status->isServerError()) {
            $this->logger->error($exception->getMessage(), $context);
        } else {
            $this->logger->warning($exception->getMessage(), $context);
        }
    }

    /**
     * Capture the error into the aggregator for grouping/tracking.
     */
    private function captureError(Throwable $exception, Request $request): void
    {
        if ($this->errorAggregator === null) {
            return;
        }

        // Build scrubbed context from request
        $context = [
            'method' => $request->method->value,
            'uri' => $request->uri,
            'query' => $request->query,
        ];

        if ($this->scrubber !== null) {
            $context = $this->scrubber->scrub($context);
        }

        // Extract trace ID from request attributes if available
        $traceId = null;
        $traceContext = $request->attribute('_trace_context');

        if ($traceContext instanceof TraceContext) {
            $traceId = $traceContext->traceId;
        }

        $event = ErrorEvent::fromThrowable($exception, $context, $traceId);
        $this->errorAggregator->capture($event);
    }

    /**
     * Render a ValidationException as a 422 JSON response.
     */
    private function renderValidationJson(ValidationException $exception): Response
    {
        return Response::validationError($exception->violations());
    }

    /**
     * Render a generic JSON error response for clients that prefer JSON.
     */
    private function renderJson(Throwable $exception, ResponseStatus $status): Response
    {
        return Response::json(
            data: [
                'error' => $status->reasonPhrase(),
                'status' => $status->value,
            ],
            status: $status,
        );
    }

    /**
     * Minimal fallback when the renderer itself fails.
     */
    private function fallbackBody(ResponseStatus $status): string
    {
        return sprintf(
            '<h1>%d %s</h1>',
            $status->value,
            htmlspecialchars($status->reasonPhrase(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
