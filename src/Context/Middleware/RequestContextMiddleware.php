<?php

declare(strict_types=1);

namespace Pulsar\Context\Middleware;

use function ctype_xdigit;
use function is_string;

use Override;
use Pulsar\Api\Api;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Random\Randomizer;

use function strlen;

/**
 * Middleware that creates and propagates RequestContext for each HTTP request.
 *
 * On inbound: reads X-Correlation-ID header (validates hex format, max 64 chars),
 * generates CausationId, and creates RequestContext from request metadata.
 * On outbound: echoes X-Correlation-ID and X-Causation-ID on response headers.
 */
#[Api]
final readonly class RequestContextMiddleware implements MiddlewareInterface
{
    private const string HEADER_CORRELATION_ID = 'X-Correlation-ID';
    private const string HEADER_CAUSATION_ID = 'X-Causation-ID';
    private const int MAX_HEADER_LENGTH = 64;

    public function __construct(
        private RequestContextHolder $holder,
        private Randomizer $randomizer,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        $correlationId = $this->resolveCorrelationId($request);
        $causationId = CausationId::generate($this->randomizer);

        // Read parent causation ID from inbound header (for causal chain tracking)
        $parentCausationId = $this->readValidHex(
            $request->header(self::HEADER_CAUSATION_ID),
        );

        $attributes = [];
        if ($parentCausationId !== null) {
            $attributes['parent_causation_id'] = $parentCausationId;
        }

        $context = new RequestContext(
            correlationId: $correlationId,
            causationId: $causationId,
            ip: $this->resolveIp($request),
            userAgent: $request->header('User-Agent'),
            attributes: $attributes,
        );

        $this->holder->set($context);

        $request = $request->withAttribute('_request_context', $context);

        try {
            /** @var Response $response */
            $response = $next($request);

            return $response
                ->withHeader(self::HEADER_CORRELATION_ID, $correlationId->value)
                ->withHeader(self::HEADER_CAUSATION_ID, $causationId->value);
        } finally {
            $this->holder->clear();
        }
    }

    private function resolveCorrelationId(Request $request): CorrelationId
    {
        $header = $request->header(self::HEADER_CORRELATION_ID);
        $validHex = $this->readValidHex($header);

        if ($validHex !== null && strlen($validHex) === 32) {
            return CorrelationId::fromString($validHex);
        }

        return CorrelationId::generate($this->randomizer);
    }

    /**
     * Validate and extract a hex string from a header value.
     *
     * Returns null if the value is missing, empty, too long, or contains non-hex characters.
     */
    private function readValidHex(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) > self::MAX_HEADER_LENGTH) {
            return null;
        }

        if (!ctype_xdigit($value)) {
            return null;
        }

        return $value;
    }

    private function resolveIp(Request $request): ?string
    {
        /** @var mixed $remoteAddr */
        $remoteAddr = $request->server['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddr) ? $remoteAddr : null;
    }
}
