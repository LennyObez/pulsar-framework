<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress\Support;

use Override;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * An {@see HttpClientInterface} that writes every POST body into a
 * {@see WireRecorder} and answers with one fixed response.
 *
 * The providers hand this client `['json' => $payload]` and leave the encoding
 * to it, so the double encodes with the same flags the providers' own
 * no-HttpClient fallback uses. What lands in the recorder is therefore the body
 * an endpoint would have read off the socket, not a PHP array that resembles it.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly WireRecorder $recorder,
        private readonly string $responseBody,
    ) {}

    #[Override]
    public function post(string $url, array $options = []): HttpResponse
    {
        /** @var mixed $payload */
        $payload = $options['json'] ?? null;

        $this->recorder->record(
            $url,
            is_array($payload)
                ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '',
        );

        return new HttpResponse(ResponseStatus::OK, new HeaderBag(), $this->responseBody);
    }

    #[Override]
    public function get(string $url, array $options = []): HttpResponse
    {
        throw new RuntimeException('This double only answers POST');
    }

    #[Override]
    public function put(string $url, array $options = []): HttpResponse
    {
        throw new RuntimeException('This double only answers POST');
    }

    #[Override]
    public function patch(string $url, array $options = []): HttpResponse
    {
        throw new RuntimeException('This double only answers POST');
    }

    #[Override]
    public function delete(string $url, array $options = []): HttpResponse
    {
        throw new RuntimeException('This double only answers POST');
    }

    #[Override]
    public function head(string $url, array $options = []): HttpResponse
    {
        throw new RuntimeException('This double only answers POST');
    }

    #[Override]
    public function options(string $url, array $options = []): HttpResponse
    {
        throw new RuntimeException('This double only answers POST');
    }
}
