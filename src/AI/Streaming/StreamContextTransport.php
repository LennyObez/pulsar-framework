<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Generator;
use Override;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Internal;

use function fclose;
use function feof;
use function fread;
use function preg_match;
use function stream_get_contents;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_set_timeout;

/**
 * Streams a POST response over PHP's native stream wrappers.
 *
 * Reads are blocking and ordinary: a generator over `fread()`, no fibers.
 * ADR-0071 records why a fiber-keyed construct is not concurrency here, and a
 * stream does not need concurrency — it needs to not buffer.
 *
 * The timeout is an IDLE timeout, not a deadline. A long answer is not a
 * failure; a silent socket is. Total generation time is deliberately uncapped,
 * because capping it would kill exactly the requests streaming exists to serve.
 *
 * Redirects are refused rather than followed: the caller validated the URL it
 * passed in against its own SSRF policy — the Ollama provider's allows
 * localhost, the cloud providers' does not — and a 302 chased in here would
 * leave that validation behind.
 */
#[Internal(reason: 'Default transport for the AI providers; depend on StreamTransportInterface')]
final readonly class StreamContextTransport implements StreamTransportInterface
{
    private HttpStreamOpenerInterface $opener;

    /**
     * @param string $provider Provider name, used only in failure messages
     * @param HttpStreamOpenerInterface|null $opener How a connection is opened; the default uses `fopen`
     * @param positive-int $chunkBytes Largest read handed to the reader at once
     */
    public function __construct(
        private string $provider,
        ?HttpStreamOpenerInterface $opener = null,
        private int $chunkBytes = 8192,
    ) {
        $this->opener = $opener ?? new StreamContextOpener($provider);
    }

    #[Override]
    public function postStream(string $url, string $body, array $headers, int $idleTimeoutSeconds): iterable
    {
        $headerLines = '';

        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $opened = $this->opener->open($url, [
            'method' => 'POST',
            'header' => $headerLines,
            'content' => $body,
            'timeout' => $idleTimeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ]);

        $status = self::statusFrom($opened->headerLines);

        $handle = $opened->handle;

        if ($status < 200 || $status > 299) {
            // The error body is small and is not a stream; reading it whole is
            // what turns an opaque failure into a message worth logging.
            $errorBody = stream_get_contents($handle);
            fclose($handle);

            throw AiStreamException::httpError($this->provider, $status, $errorBody === false ? '' : $errorBody);
        }

        return $this->read($handle, $url, $idleTimeoutSeconds);
    }

    /**
     * The status code from a set of raw response header lines.
     *
     * Informational 1xx responses put their own status line first, so the last
     * one is the one that counts.
     *
     * @param list<string> $headerLines
     */
    public static function statusFrom(array $headerLines): int
    {
        $status = 0;

        foreach ($headerLines as $line) {
            $matches = [];

            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /**
     * The response body, in whatever pieces arrive.
     *
     * Public because it is the whole of this class that can be proved without a
     * socket, and a check that cannot run is not a check (ADR-0060).
     *
     * @param resource $handle
     *
     * @return Generator<int, string, mixed, void>
     *
     * @throws AiStreamException On a stall or a connection that dies mid-body
     */
    public function read(mixed $handle, string $url, int $idleTimeoutSeconds): Generator
    {
        stream_set_blocking($handle, true);
        stream_set_timeout($handle, $idleTimeoutSeconds);

        try {
            while (true) {
                $chunk = fread($handle, $this->chunkBytes);
                $meta = stream_get_meta_data($handle);

                if ($meta['timed_out'] === true) {
                    throw AiStreamException::stalled($this->provider, $idleTimeoutSeconds, '');
                }

                if ($chunk === false) {
                    throw AiStreamException::transportFailure($this->provider, $url, 'the connection failed mid-body');
                }

                if ($chunk !== '') {
                    yield $chunk;

                    continue;
                }

                if (feof($handle)) {
                    break;
                }

                // A blocking read returned nothing while claiming neither EOF nor
                // a timeout: the peer is gone and looping would spin forever.
                throw AiStreamException::transportFailure($this->provider, $url, 'the peer closed the connection');
            }
        } finally {
            fclose($handle);
        }
    }
}
