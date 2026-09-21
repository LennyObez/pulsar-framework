<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Provider\Support;

use Override;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use RuntimeException;

/**
 * An {@see HttpClientInterface} whose every request fails as a refused connection, recording
 * the URL it was asked for. A test learns that a URL got past validation to the transport
 * without opening a socket: a real connection attempt waits on the machine's network.
 */
final class RefusingHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    public private(set) array $requested = [];

    #[Override]
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    #[Override]
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    #[Override]
    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    #[Override]
    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    #[Override]
    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    #[Override]
    public function head(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    #[Override]
    public function options(string $url, array $options = []): HttpResponse
    {
        return $this->refuse($url);
    }

    private function refuse(string $url): never
    {
        $this->requested[] = $url;

        throw new RuntimeException('Connection refused: ' . $url);
    }
}
