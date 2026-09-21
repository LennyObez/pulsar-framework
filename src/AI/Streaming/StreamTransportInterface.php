<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Api;

/**
 * Sends one request and hands back its response body as it arrives.
 *
 * This is the seam the providers stream through, and the seam a test replaces.
 * It is deliberately narrower than {@see \Pulsar\Http\Client\HttpClientInterface},
 * which returns a fully-read {@see \Pulsar\Http\Client\HttpResponse} and so can
 * never be the thing a stream is read from.
 *
 * Implementations MUST NOT follow redirects. The caller validated the URL it
 * passed in against its own SSRF policy — the Ollama provider's policy allows
 * localhost, the cloud providers' does not — and a redirect chased inside the
 * transport would leave that validation behind.
 * @api
 */
#[Api(since: '1.0.0')]
interface StreamTransportInterface
{
    /**
     * POST a body and yield the response body in whatever pieces arrive.
     *
     * The pieces carry no framing meaning: a chunk may end mid-line and a line
     * may span chunks. Framing is the reader's job.
     *
     * @param array<string, string> $headers Request headers
     * @param int $idleTimeoutSeconds Maximum silence between pieces before the
     *                                stream is declared dead. Total duration is
     *                                deliberately not capped.
     *
     * @return iterable<int, string> Non-empty body pieces
     *
     * @throws AiStreamException On connection failure, a non-2xx status, or a stall
     */
    public function postStream(string $url, string $body, array $headers, int $idleTimeoutSeconds): iterable;
}
