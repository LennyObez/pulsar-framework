<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Generator;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

use function is_array;
use function json_decode;

/**
 * Turns OpenAI chat-completion SSE frames into {@see AiStreamDelta} objects.
 *
 * OpenAI names none of its frames: every one is an unnamed `data:` payload, and
 * the stream ends with the literal `data: [DONE]`. Content arrives on
 * `choices[0].delta.content`, tool calls arrive as indexed entries under
 * `choices[0].delta.tool_calls` whose `function.arguments` are JSON string
 * slices, the stop reason arrives as `choices[0].finish_reason`, and usage —
 * requested by the provider through `stream_options.include_usage` — arrives on
 * a trailing chunk that has no choices at all.
 *
 * `[DONE]` is deliberately NOT treated as the terminal event: it says the
 * connection is finished, not that the model finished. Only `finish_reason`
 * makes a stream complete, which is what keeps a socket that closed early from
 * looking like a clean stop.
 */
#[Internal(reason: 'Wire format detail of OpenAiProvider; consume AiStreamDelta instead')]
final readonly class OpenAiStreamParser
{
    /**
     * @param iterable<int, string> $chunks Raw body pieces from the transport
     *
     * @return Generator<int, AiStreamDelta, mixed, void>
     *
     * @throws AiStreamException On an error payload or an undecodable frame
     */
    public function deltas(iterable $chunks): Generator
    {
        foreach (SseReader::events($chunks) as $event) {
            if ($event->data === '[DONE]') {
                return;
            }

            yield from $this->fromPayload($this->decode($event->data));
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return Generator<int, AiStreamDelta, mixed, void>
     *
     * @throws AiStreamException
     */
    private function fromPayload(array $payload): Generator
    {
        if (isset($payload['error'])) {
            throw AiStreamException::providerError('openai', $this->errorMessage($payload));
        }

        $choice = $this->firstChoice($payload);
        $delta = $this->child($choice, 'delta');

        $content = Coerce::string($delta['content'] ?? null);

        if ($content !== '') {
            yield AiStreamDelta::text($content);
        }

        yield from $this->fromToolCalls($delta);

        $usage = $this->child($payload, 'usage');

        $tokens = new AiTokenUsage(
            inputTokens: Coerce::nullableInt($usage['prompt_tokens'] ?? null),
            outputTokens: Coerce::nullableInt($usage['completion_tokens'] ?? null),
        );

        if (!$tokens->isEmpty()) {
            yield AiStreamDelta::usage($tokens);
        }

        $finishReason = Coerce::nullableString($choice['finish_reason'] ?? null);

        if ($finishReason !== null && $finishReason !== '') {
            yield AiStreamDelta::finish($finishReason);
        }
    }

    /**
     * @param array<string, mixed> $delta
     *
     * @return Generator<int, AiStreamDelta, mixed, void>
     */
    private function fromToolCalls(array $delta): Generator
    {
        /** @var mixed $rawToolCalls */
        $rawToolCalls = $delta['tool_calls'] ?? null;

        if (!is_array($rawToolCalls)) {
            return;
        }

        $position = 0;

        /** @var mixed $entry */
        foreach ($rawToolCalls as $entry) {
            if (!is_array($entry)) {
                ++$position;

                continue;
            }

            /** @var array<string, mixed> $call */
            $call = $entry;
            $function = $this->child($call, 'function');

            yield AiStreamDelta::toolCall(new ToolCallDelta(
                // The wire index is authoritative; the array position is only a
                // fallback for compatible endpoints that omit it.
                index: Coerce::int($call['index'] ?? null, $position),
                id: Coerce::nullableString($call['id'] ?? null),
                name: Coerce::nullableString($function['name'] ?? null),
                argumentsFragment: Coerce::string($function['arguments'] ?? null),
            ));

            ++$position;
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function firstChoice(array $payload): array
    {
        /** @var mixed $choices */
        $choices = $payload['choices'] ?? null;

        if (!is_array($choices)) {
            return [];
        }

        /** @var mixed $first */
        $first = $choices[0] ?? null;

        /** @var array<string, mixed> */
        return is_array($first) ? $first : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function errorMessage(array $payload): string
    {
        $error = $this->child($payload, 'error');
        $message = Coerce::string($error['message'] ?? null);

        return $message === '' ? 'unknown error' : $message;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function child(array $payload, string $key): array
    {
        /** @var mixed $value */
        $value = $payload[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiStreamException When the frame was cut mid-JSON
     */
    private function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            throw AiStreamException::providerError(
                'openai',
                'a stream frame did not contain a JSON object, so it was cut mid-frame',
            );
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
