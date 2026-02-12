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
 * OpenAI-compatible LLM provider.
 *
 * Works with OpenAI, Azure OpenAI, and any API that follows the
 * OpenAI chat completions format (e.g., Ollama, vLLM, LiteLLM).
 */
#[Internal(reason: 'LLM provider implementation — use LlmProviderInterface')]
final readonly class OpenAiProvider implements LlmProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gpt-4o',
        private string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    #[Override]
    public function complete(string $prompt, LlmOptions $options = new LlmOptions()): LlmResponse
    {
        $messages = [];

        if ($options->systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $options->systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = array_filter([
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $options->temperature,
            'max_tokens' => $options->maxTokens,
        ]);

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . "Authorization: Bearer {$this->apiKey}\r\n",
                'content' => $json,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return new LlmResponse(
                content: 'Failed to connect to OpenAI API',
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'error',
            );
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return new LlmResponse(
                content: 'Invalid JSON response from OpenAI API',
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'error',
            );
        }

        if (isset($data['error'])) {
            /** @var array{message?: string} $error */
            $error = (array) $data['error'];

            return new LlmResponse(
                content: (string) ($error['message'] ?? 'Unknown OpenAI API error'),
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'error',
            );
        }

        /** @var array{choices?: list<array{message?: array{content?: string}, finish_reason?: string}>, usage?: array{prompt_tokens?: int, completion_tokens?: int}} $data */
        $choices = (array) ($data['choices'] ?? []);
        $firstChoice = (array) ($choices[0] ?? []);
        $message = (array) ($firstChoice['message'] ?? []);
        $usage = (array) ($data['usage'] ?? []);

        return new LlmResponse(
            content: (string) ($message['content'] ?? ''),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            finishReason: (string) ($firstChoice['finish_reason'] ?? 'stop'),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'openai';
    }
}
