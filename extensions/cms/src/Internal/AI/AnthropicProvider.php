<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\AI;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\AI\LlmOptions;
use Pulsar\Extension\Cms\AI\LlmProviderInterface;
use Pulsar\Extension\Cms\AI\LlmResponse;

use function array_filter;
use function is_array;
use function json_decode;
use function json_encode;
use function stream_context_create;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Anthropic Claude LLM provider.
 *
 * Connects to the Anthropic Messages API to provide completions.
 *
 * @psalm-api Bound to LlmProviderInterface in the CMS AI service provider;
 *            Psalm cannot trace the string-keyed interface dispatch.
 */
#[Internal(reason: 'LLM provider implementation — use LlmProviderInterface')]
final readonly class AnthropicProvider implements LlmProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $model = 'claude-sonnet-4-6',
        private string $baseUrl = 'https://api.anthropic.com/v1',
    ) {}

    #[Override]
    public function complete(string $prompt, LlmOptions $options = new LlmOptions()): LlmResponse
    {
        $payload = array_filter([
            'model' => $this->model,
            'max_tokens' => $options->maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'system' => $options->systemPrompt,
            'temperature' => $options->temperature,
        ], static fn(mixed $v): bool => $v !== null);

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . "x-api-key: {$this->apiKey}\r\n"
                    . "anthropic-version: 2023-06-01\r\n",
                'content' => $json,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $url = rtrim($this->baseUrl, '/') . '/messages';
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return new LlmResponse(
                content: 'Failed to connect to Anthropic API',
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'error',
            );
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return new LlmResponse(
                content: 'Invalid JSON response from Anthropic API',
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'error',
            );
        }

        if (isset($data['error'])) {
            /** @var array{message?: string} $error */
            $error = (array) $data['error'];

            return new LlmResponse(
                content: (string) ($error['message'] ?? 'Unknown Anthropic API error'),
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'error',
            );
        }

        /** @var array{content?: list<array{text?: string}>, usage?: array{input_tokens?: int, output_tokens?: int}, stop_reason?: string} $data */
        $contentBlocks = (array) ($data['content'] ?? []);
        $firstBlock = (array) ($contentBlocks[0] ?? []);
        $usage = (array) ($data['usage'] ?? []);

        $stopReason = (string) ($data['stop_reason'] ?? 'end_turn');
        $finishReason = match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            default => $stopReason,
        };

        return new LlmResponse(
            content: (string) ($firstBlock['text'] ?? ''),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            finishReason: $finishReason,
        );
    }

    #[Override]
    public function name(): string
    {
        return 'anthropic';
    }
}
