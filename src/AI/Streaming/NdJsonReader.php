<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Generator;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Internal;

use function is_array;
use function json_decode;
use function strpos;
use function substr;
use function trim;

/**
 * Frames a byte stream into newline-delimited JSON objects.
 *
 * Ollama does not speak SSE: `/api/chat` with `"stream": true` writes one JSON
 * object per line. The trailing line matters — Ollama's final object, the one
 * carrying `done` and the token counts, is the last thing on the wire and may
 * arrive without its newline. It is decoded rather than discarded, and a line
 * that was cut mid-object fails to decode and fails the stream, which is the
 * distinction that has to hold.
 */
#[Internal(reason: 'Wire format detail of the Ollama provider; consume AiStreamDelta instead')]
final readonly class NdJsonReader
{
    public function __construct(private string $provider = 'ollama') {}

    /**
     * @param iterable<int, string> $chunks Raw body pieces from the transport
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     *
     * @throws AiStreamException When a line is not a JSON object
     */
    public function objects(iterable $chunks): Generator
    {
        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);

                if ($line === '') {
                    continue;
                }

                yield $this->decodeLine($line);
            }
        }

        $tail = trim($buffer);

        if ($tail === '') {
            return;
        }

        yield $this->decodeLine($tail);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiStreamException
     */
    private function decodeLine(string $line): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($line, true);

        if (!is_array($decoded)) {
            throw AiStreamException::providerError(
                $this->provider,
                'the stream contained a line that is not a JSON object, so it was cut mid-object',
            );
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
