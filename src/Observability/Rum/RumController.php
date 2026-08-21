<?php

declare(strict_types=1);

namespace Pulsar\Observability\Rum;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function strcasecmp;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;

/**
 * Collection endpoint for browser-reported Real User Monitoring metrics.
 *
 * This is the one framework route that takes a request body from an
 * unauthenticated browser and turns it into server state, so what it accepts,
 * from whom, and what stops it being an open beacon are worth stating rather
 * than leaving to be inferred.
 *
 * WHAT IT ACCEPTS: a JSON object `{"metrics": [{name, value, url}, ...]}`. The
 * name must be one of eight known web-vitals identifiers
 * ({@see RumCollector}); the value must be numeric; the url is reduced to a
 * path and clamped for cardinality ({@see RumUrlLabels}). Anything else in the
 * batch is counted as rejected and dropped. The batch is capped at 100 entries
 * and the body at {@see RumController::MAX_BODY_BYTES}.
 *
 * FROM WHOM: same-origin browsers only. The request must carry an `Origin`
 * header whose host equals the host the request was addressed to. Every browser
 * sends `Origin` on a POST — including `navigator.sendBeacon`, which is how the
 * client script reports — so requiring it costs a real client nothing, while a
 * `<script>` on an unrelated site, an `<img>`-style beacon, or a form post from
 * elsewhere all arrive with a foreign `Origin` or none and are refused before
 * the body is read. Ports and scheme are not compared: a TLS-terminating proxy
 * legitimately leaves the worker seeing `http` on port 80 for a request the
 * browser made to `https`.
 *
 * WHAT STOPS IT BEING AN OPEN BEACON: the origin check above bounds who can
 * drive it from a browser; the body cap bounds one request; the batch cap bounds
 * one body; and the label budget bounds the damage a caller who ignores all
 * three can do to the metrics registry — the one effect that used to persist
 * after the request ended. The route itself is registered only in debug builds
 * ({@see \Pulsar\Core\Wiring\DiagnosticsWiring}), so in production it does
 * not exist at all.
 *
 * A same-origin check is not an authentication check, and this class does not
 * pretend otherwise: a non-browser caller sets any header it likes. It is the
 * control that matches the threat — an anonymous endpoint whose abuse vector is
 * a page on someone else's site pointing at it.
 */
#[Internal]
final readonly class RumController
{
    /** Largest body accepted; a full 100-metric batch is well under 8 KiB. */
    public const int MAX_BODY_BYTES = 16384;

    public function __construct(
        private RumCollector $collector,
    ) {}

    /**
     * Handle POST /_pulsar/rum/collect.
     *
     * Returns 403 for a cross-origin caller, 413 for an oversized body,
     * 400 on malformed input, and a per-batch accepted/rejected tally otherwise.
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!self::isSameOrigin($request)) {
            return Response::json(['error' => 'Cross-origin collection is not accepted'], 403);
        }

        $declaredSize = $request->getBody()->getSize();

        if ($declaredSize !== null && $declaredSize > self::MAX_BODY_BYTES) {
            return Response::json(['error' => 'Payload too large'], 413);
        }

        $body = (string) $request->getBody();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return Response::json(['error' => 'Payload too large'], 413);
        }

        if ($body === '') {
            return Response::json(['error' => 'Empty body'], 400);
        }

        try {
            /** @var array<string, mixed>|null $payload */
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        if (!is_array($payload)) {
            return Response::json(['error' => 'Invalid JSON'], 400);
        }

        $result = $this->collector->collect($payload);

        return Response::json([
            'accepted' => $result->accepted,
            'rejected' => $result->rejected,
        ]);
    }

    /**
     * Whether the browser that issued this POST was on the site's own origin.
     *
     * A missing `Origin` is a refusal, not a pass: browsers attach it to every
     * POST, so its absence means the caller is not the client script this
     * endpoint exists to serve.
     */
    private static function isSameOrigin(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return false;
        }

        /** @var string|false|null $originHost */
        $originHost = parse_url($origin, PHP_URL_HOST);

        if (!is_string($originHost) || $originHost === '') {
            return false;
        }

        $requestHost = $request->getUri()->getHost();

        if ($requestHost === '') {
            return false;
        }

        return strcasecmp($originHost, $requestHost) === 0;
    }
}
