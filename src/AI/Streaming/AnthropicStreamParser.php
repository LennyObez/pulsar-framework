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
 * Turns Anthropic Messages API SSE frames into {@see AiStreamDelta} objects.
 *
 * Anthropic names its frames: `message_start` opens with the prompt token count,
 * `content_block_start` announces a block (text, or a `tool_use` with its id and
 * name), `content_block_delta` carries either text or a slice of the tool's JSON
 * arguments, and `message_delta` closes with the stop reason and the final output
 * token count. The stop reasons are mapped to the same vocabulary
 * {@see \Pulsar\AI\Provider\AnthropicProvider} already uses unstreamed, so an
 * accumulated response is comparable to an unstreamed one field for field.
 */
#[Internal(reason: 'Wire format detail of AnthropicProvider; consume AiStreamDelta instead')]
final readonly class AnthropicStreamParser
{
    /**
     * @param iterable<int, string> $chunks Raw body pieces from the transport
     *
     * @return Generator<int, AiStreamDelta, mixed, void>
     *
     * @throws AiStreamException On an error frame or an undecodable payload
     */
    public function deltas(iterable $chunks): Generator
    {
        foreach (SseReader::events($chunks) as $event) {
            yield from $this->fromEvent($event);
        }
    }

    /**
     * @return Generator<int, AiStreamDelta, mixed, void>
     *
     * @throws AiStreamException
     */
    private function fromEvent(SseEvent $event): Generator
    {
        $payload = $this->decode($event->data);
        $type = Coerce::string($payload['type'] ?? $event->name);

        if ($type === 'error') {
            throw AiStreamException::providerError('anthropic', $this->errorMessage($payload));
        }

        // ping, content_block_stop and message_stop reach the default arm: they
        // are framing, not facts.
        yield from match ($type) {
            'message_start' => $this->fromMessageStart($payload),
            'content_block_start' => $this->fromContentBlockStart($payload),
            'content_block_delta' => $this->fromContentBlockDelta($payload),
            'message_delta' => $this->fromMessageDelta($payload),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<AiStreamDelta>
     */
    private function fromMessageStart(array $payload): array
    {
        $message = $this->child($payload, 'message');
        $usage = $this->child($message, 'usage');

        $tokens = new AiTokenUsage(
            inputTokens: Coerce::nullableInt($usage['input_tokens'] ?? null),
            outputTokens: Coerce::nullableInt($usage['output_tokens'] ?? null),
        );

        return $tokens->isEmpty() ? [] : [AiStreamDelta::usage($tokens)];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<AiStreamDelta>
     */
    private function fromContentBlockStart(array $payload): array
    {
        $block = $this->child($payload, 'content_block');

        if (Coerce::string($block['type'] ?? null) !== 'tool_use') {
            return [];
        }

        return [AiStreamDelta::toolCall(new ToolCallDelta(
            index: Coerce::int($payload['index'] ?? null, 0),
            id: Coerce::nullableString($block['id'] ?? null),
            name: Coerce::nullableString($block['name'] ?? null),
        ))];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<AiStreamDelta>
     */
    private function fromContentBlockDelta(array $payload): array
    {
        $delta = $this->child($payload, 'delta');
        $type = Coerce::string($delta['type'] ?? null);

        if ($type === 'text_delta') {
            $text = Coerce::string($delta['text'] ?? null);

            return $text === '' ? [] : [AiStreamDelta::text($text)];
        }

        if ($type !== 'input_json_delta') {
            return [];
        }

        return [AiStreamDelta::toolCall(new ToolCallDelta(
            index: Coerce::int($payload['index'] ?? null, 0),
            argumentsFragment: Coerce::string($delta['partial_json'] ?? null),
        ))];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<AiStreamDelta>
     */
    private function fromMessageDelta(array $payload): array
    {
        $deltas = [];
        $usage = $this->child($payload, 'usage');

        $tokens = new AiTokenUsage(
            inputTokens: Coerce::nullableInt($usage['input_tokens'] ?? null),
            outputTokens: Coerce::nullableInt($usage['output_tokens'] ?? null),
        );

        if (!$tokens->isEmpty()) {
            // Emitted before the terminal event so a consumer watching usage
            // sees the final count no later than it sees the finish.
            $deltas[] = AiStreamDelta::usage($tokens);
        }

        $delta = $this->child($payload, 'delta');
        $stopReason = Coerce::nullableString($delta['stop_reason'] ?? null);

        if ($stopReason === null) {
            return $deltas;
        }

        $deltas[] = AiStreamDelta::finish(match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_use',
            default => $stopReason,
        });

        return $deltas;
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
                'anthropic',
                'a stream frame did not contain a JSON object, so it was cut mid-frame',
            );
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
