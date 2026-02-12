<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Emits an HTTP response to the client.
 */
#[Api(since: '1.0.0')]
final class ResponseEmitter
{
    /**
     * Emit the response to the client.
     *
     * This sends headers and outputs the body. Should only be called once.
     */
    public function emit(ResponseInterface $response): void
    {
        $this->assertHeadersNotSent();

        $this->emitStatusLine($response);
        $this->emitHeaders($response);
        $this->emitBody($response);
    }

    /**
     * Emit the HTTP status line.
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        $statusLine = sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        );

        header($statusLine, true, $response->getStatusCode());
    }

    /**
     * Emit all response headers.
     */
    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header(
                    sprintf('%s: %s', $name, $value),
                    $first,
                );
                $first = false;
            }
        }
    }

    /**
     * Emit the response body.
     */
    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->getSize() === 0) {
            return;
        }

        echo $body;
    }

    /**
     * Assert that headers have not already been sent.
     *
     * @throws RuntimeException If headers were already sent
     */
    private function assertHeadersNotSent(): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf(
                'Headers already sent in %s on line %d. Cannot emit response.',
                $file,
                $line,
            ));
        }
    }
}
