<?php

declare(strict_types=1);

namespace Pulsar\Http;

use RuntimeException;

use function sprintf;

/**
 * Emits an HTTP response to the client.
 */
final class ResponseEmitter
{
    /**
     * Emit the response to the client.
     *
     * This sends headers and outputs the body. Should only be called once.
     */
    public function emit(Response $response): void
    {
        $this->assertHeadersNotSent();

        $this->emitStatusLine($response);
        $this->emitHeaders($response);
        $this->emitBody($response);
    }

    /**
     * Emit the HTTP status line.
     */
    private function emitStatusLine(Response $response): void
    {
        $statusLine = sprintf(
            'HTTP/%s %d %s',
            $response->protocolVersion,
            $response->status->value,
            $response->status->reasonPhrase(),
        );

        header($statusLine, true, $response->status->value);
    }

    /**
     * Emit all response headers.
     */
    private function emitHeaders(Response $response): void
    {
        foreach ($response->headers as $name => $values) {
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
    private function emitBody(Response $response): void
    {
        if ($response->isEmpty()) {
            return;
        }

        echo $response->body;
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
