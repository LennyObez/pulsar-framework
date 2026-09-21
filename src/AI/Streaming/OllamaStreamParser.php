<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Generator;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Turns Ollama `/api/chat` newline-delimited JSON objects into
 * {@see AiStreamDelta} objects.
 *
 * Ollama streams whole objects rather than fragments: each carries the next
 * slice of `message.content`, and the last carries `done: true` with
 * `done_reason`, `prompt_eval_count` and `eval_count`. Tool calls arrive
 * complete, as an arguments object rather than a JSON string — they are
 * re-encoded into a single fragment so that {@see AiStreamAccumulator} folds
 * every provider's tool calls the same way instead of branching on which one
 * fragments them.
 */
#[Internal(reason: 'Wire format detail of OllamaProvider; consume AiStreamDelta instead')]
final readonly class OllamaStreamParser
{
    public function __construct(private NdJsonReader $reader = new NdJsonReader()) {}

    /**
     * @param iterable<int, string> $chunks Raw body pieces from the transport
     *
     * @return Generator<int, AiStreamDelta, mixed, void>
     *
     * @throws AiStreamException On an error object or a line cut mid-object
     */
    public function deltas(iterable $chunks): Generator
    {
        $toolIndex = 0;

        foreach ($this->reader->objects($chunks) as $object) {
            if (isset($object['error'])) {
                $reported = Coerce::string($object['error']);

                throw AiStreamException::providerError('ollama', $reported === '' ? 'unknown error' : $reported);
            }

            $message = $this->child($object, 'message');
            $content = Coerce::string($message['content'] ?? null);

            if ($content !== '') {
                yield AiStreamDelta::text($content);
            }

            foreach ($this->toolCallDeltas($message, $toolIndex) as $delta) {
                yield $delta;
            }

            if (!Coerce::bool($object['done'] ?? null)) {
                continue;
            }

            $tokens = new AiTokenUsage(
                inputTokens: Coerce::nullableInt($object['prompt_eval_count'] ?? null),
                outputTokens: Coerce::nullableInt($object['eval_count'] ?? null),
            );

            if (!$tokens->isEmpty()) {
                yield AiStreamDelta::usage($tokens);
            }

            $doneReason = Coerce::nullableString($object['done_reason'] ?? null);

            yield AiStreamDelta::finish($doneReason ?? 'stop');
        }
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return list<AiStreamDelta>
     */
    private function toolCallDeltas(array $message, int &$toolIndex): array
    {
        /** @var mixed $rawToolCalls */
        $rawToolCalls = $message['tool_calls'] ?? null;

        if (!is_array($rawToolCalls)) {
            return [];
        }

        $deltas = [];

        /** @var mixed $entry */
        foreach ($rawToolCalls as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $call */
            $call = $entry;
            $function = $this->child($call, 'function');

            /** @var mixed $rawArguments */
            $rawArguments = $function['arguments'] ?? null;
            /** @var array<string, mixed> $arguments */
            $arguments = is_array($rawArguments) ? $rawArguments : [];

            $deltas[] = AiStreamDelta::toolCall(new ToolCallDelta(
                index: $toolIndex,
                // Ollama assigns no call id, so none is reported. Synthesising
                // one here would be inventing a fact the provider never stated.
                id: Coerce::nullableString($call['id'] ?? null),
                name: Coerce::nullableString($function['name'] ?? null),
                argumentsFragment: $arguments === []
                    ? ''
                    : json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));

            ++$toolIndex;
        }

        return $deltas;
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
}
