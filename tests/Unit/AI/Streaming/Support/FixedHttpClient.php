<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming\Support;

use Override;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

/**
 * An {@see HttpClientInterface} that answers every POST with one fixed body.
 *
 * The streamed and unstreamed paths have to be driven from the same fixture for
 * the equality claim to mean anything, and the unstreamed path goes through the
 * HTTP client rather than the stream transport.
 */
final class FixedHttpClient implements HttpClientInterface
{
    public string $url = '';

    public function __construct(private readonly string $responseBody) {}

    #[Override]
    public function post(string $url, array $options = []): HttpResponse
    {
        $this->url = $url;

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
