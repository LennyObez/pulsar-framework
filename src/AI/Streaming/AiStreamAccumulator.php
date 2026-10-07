<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use NoDiscard;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\ToolCall;
use Pulsar\Api\Api;

use function is_array;
use function json_decode;
use function ksort;

/**
 * Folds a stream's deltas back into the {@see AiResponse} the same request would
 * have returned unstreamed.
 *
 * Token accounting is why this exists rather than a `implode()` over the text
 * deltas. Budgets and audit records are keyed on `inputTokens`/`outputTokens`,
 * so a streamed call that could not produce them would quietly exempt itself
 * from both. The numbers are taken from the provider's own usage events and are
 * never inferred: a provider that reports nothing yields zero, which is the same
 * thing the unstreamed parsers already do with a missing `usage` block.
 *
 * The finish reason is likewise never written as a literal. {@see finish()}
 * refuses unless a terminal event was actually observed, which is what makes a
 * dropped connection distinguishable from a finished answer.
 */
#[Api(since: '1.0.0')]
final class AiStreamAccumulator
{
    private string $content = '';

    /** @var array<int, array{id: string, name: string, arguments: string}> */
    private array $toolFragments = [];

    private ?int $inputTokens = null;

    private ?int $outputTokens = null;

    private ?string $finishReason = null;

    /**
     * @param string $provider Provider name, used only in failure messages
     * @param string $model The model the request asked for
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model = '',
    ) {}

    /**
     * Record one delta.
     */
    public function accept(AiStreamDelta $delta): void
    {
        match ($delta->type) {
            AiStreamEventType::Text => $this->acceptText($delta->text),
            AiStreamEventType::ToolCall => $this->acceptToolCall($delta->toolCall),
            AiStreamEventType::Usage => $this->acceptUsage($delta->usage),
            AiStreamEventType::Finish => $this->acceptFinish($delta->finishReason),
        };
    }

    /**
     * Whether a terminal event has been observed.
     */
    #[NoDiscard]
    public function isFinished(): bool
    {
        return $this->finishReason !== null;
    }

    /**
     * The text received so far.
     *
     * A plain string, never an {@see AiResponse}: what arrived before a failure
     * is evidence, not an answer.
     */
    #[NoDiscard]
    public function partialContent(): string
    {
        return $this->content;
    }

    /**
     * The complete response.
     *
     * @throws AiStreamException When no terminal event was observed, or when a
     *                           tool call's arguments never became a JSON object
     */
    #[NoDiscard]
    public function finish(): AiResponse
    {
        if ($this->finishReason === null) {
            throw AiStreamException::truncated($this->provider, $this->content);
        }

        return new AiResponse(
            content: $this->content,
            inputTokens: $this->inputTokens ?? 0,
            outputTokens: $this->outputTokens ?? 0,
            finishReason: $this->finishReason,
            toolCalls: $this->toolCalls(),
            model: $this->model,
        );
    }

    private function acceptText(string $text): void
    {
        $this->content .= $text;
    }

    private function acceptFinish(?string $reason): void
    {
        // A Finish delta always carries a reason; the named constructor is the
        // only way to build one. Guarding anyway keeps `isFinished()` honest
        // rather than letting a null masquerade as a terminal event.
        if ($reason === null) {
            return;
        }

        $this->finishReason = $reason;
    }

    private function acceptToolCall(?ToolCallDelta $fragment): void
    {
        if ($fragment === null) {
            return;
        }

        $entry = $this->toolFragments[$fragment->index] ?? ['id' => '', 'name' => '', 'arguments' => ''];

        if ($fragment->id !== null && $fragment->id !== '') {
            $entry['id'] = $fragment->id;
        }

        if ($fragment->name !== null && $fragment->name !== '') {
            $entry['name'] = $fragment->name;
        }

        $entry['arguments'] .= $fragment->argumentsFragment;

        $this->toolFragments[$fragment->index] = $entry;
    }

    private function acceptUsage(?AiTokenUsage $usage): void
    {
        if ($usage === null) {
            return;
        }

        // Last report wins per field. Anthropic opens with the input count and
        // closes with the final output count, so overwriting only the fields an
        // event actually carried is what keeps both.
        if ($usage->inputTokens !== null) {
            $this->inputTokens = $usage->inputTokens;
        }

        if ($usage->outputTokens !== null) {
            $this->outputTokens = $usage->outputTokens;
        }
    }

    /**
     * @return list<ToolCall>
     *
     * @throws AiStreamException When reassembled arguments are not a JSON object
     */
    private function toolCalls(): array
    {
        $fragments = $this->toolFragments;
        ksort($fragments);

        $calls = [];

        foreach ($fragments as $entry) {
            $calls[] = new ToolCall(
                id: $entry['id'],
                name: $entry['name'],
                arguments: $this->decodeArguments($entry['name'], $entry['arguments']),
            );
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiStreamException
     */
    private function decodeArguments(string $name, string $raw): array
    {
        if ($raw === '') {
            // A tool that takes no parameters streams no argument fragments.
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw AiStreamException::malformedToolCall($this->provider, $name, $raw);
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
