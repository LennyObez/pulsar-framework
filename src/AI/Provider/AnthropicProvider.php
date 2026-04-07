<?php

declare(strict_types=1);

namespace Pulsar\AI\Provider;

use Override;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\ChatRole;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\ToolCall;
use Pulsar\AI\ToolDefinition;
use Pulsar\Api\Api;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Security\Validation\UrlSafetyValidator;
use Throwable;

use function array_filter;
use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Anthropic Claude API provider.
 *
 * Supports chat, completion, structured output, and tool calling
 * via the Anthropic Messages API.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AnthropicProvider implements AiClientInterface
{
    private string $defaultModel;

    public function __construct(
        private string $apiKey,
        string $model = 'claude-sonnet-4-6',
        private string $baseUrl = 'https://api.anthropic.com/v1',
        private ?HttpClientInterface $httpClient = null,
    ) {
        $this->defaultModel = $model;
    }

    #[Override]
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        $model = $options->model ?? $this->defaultModel;
        $systemPrompt = $options->systemPrompt;
        $apiMessages = [];

        foreach ($messages as $msg) {
            if ($msg->role === ChatRole::System) {
                $systemPrompt ??= $msg->content;
                continue;
            }

            $apiMessages[] = [
                'role' => $msg->role === ChatRole::Assistant ? 'assistant' : 'user',
                'content' => $msg->content,
            ];
        }

        $payload = array_filter([
            'model' => $model,
            'max_tokens' => $options->maxTokens ?? 1024,
            'messages' => $apiMessages,
            'system' => $systemPrompt,
            'temperature' => $options->temperature,
        ], static fn(mixed $v): bool => $v !== null);

        if ($options->tools !== []) {
            $payload['tools'] = array_map(
                static fn(ToolDefinition $tool): array => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'input_schema' => $tool->parameters,
                ],
                $options->tools,
            );
        }

        if ($options->responseFormat === 'json') {
            $payload['messages'][] = [
                'role' => 'user',
                'content' => 'Respond with valid JSON only. No markdown, no explanation.',
            ];
        }

        return $this->sendRequest('/messages', $payload, $model, $options->timeoutSeconds);
    }

    #[Override]
    public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        $messages = [ChatMessage::user($prompt)];

        return $this->chat($messages, $options);
    }

    #[Override]
    public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): EmbeddingResult
    {
        throw AiException::unsupportedCapability(
            $options->model ?? $this->defaultModel,
            'embeddings (Anthropic does not offer embedding models)',
        );
    }

    #[Override]
    public function structuredOutput(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): AiResponse {
        $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $wrappedPrompt = $prompt . "\n\nRespond with valid JSON matching this schema:\n" . $schemaJson;

        return $this->chat(
            [ChatMessage::user($wrappedPrompt)],
            new AiRequestOptions(
                temperature: $options->temperature ?? 0.0,
                maxTokens: $options->maxTokens,
                systemPrompt: $options->systemPrompt ?? 'You are a structured data extraction assistant. Always respond with valid JSON matching the provided schema. No markdown, no explanation.',
                model: $options->model,
                timeoutSeconds: $options->timeoutSeconds,
                responseFormat: 'json',
            ),
        );
    }

    #[Override]
    public function providerName(): string
    {
        return 'anthropic';
    }

    /**
     * Validate the outbound URL is safe against SSRF (CWE-918).
     *
     * Cloud AI providers must use HTTPS and must not resolve to private networks.
     *
     * @throws AiException When the URL targets a private/reserved address
     */
    private function validateUrl(string $url): void
    {
        $result = UrlSafetyValidator::validate($url);

        if (!$result->safe) {
            throw AiException::ssrfBlocked('anthropic', $url, $result->reason);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws AiException When the URL fails SSRF validation
     */
    private function sendRequest(string $path, array $payload, string $model, int $timeout): AiResponse
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $this->validateUrl($url);

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        if ($this->httpClient !== null) {
            try {
                $httpResponse = $this->httpClient->post($url, [
                    'json' => $payload,
                    'headers' => $headers,
                    'timeout' => $timeout,
                ]);

                $responseBody = $httpResponse->body();
            } catch (Throwable) {
                return AiResponse::error('Failed to connect to Anthropic API');
            }
        } else {
            // Fallback: stream_context_create for environments without HttpClient
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n"
                        . "x-api-key: $this->apiKey\r\n"
                        . "anthropic-version: 2023-06-01\r\n",
                    'content' => $json,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
            ]);

            $responseBody = @file_get_contents($url, false, $context);

            if ($responseBody === false) {
                return AiResponse::error('Failed to connect to Anthropic API');
            }
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            return AiResponse::error('Invalid JSON response from Anthropic API');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        if (isset($data['error'])) {
            /** @var array{message?: string} $error */
            $error = (array) $data['error'];
            $rawErrorMessage = $error['message'] ?? null;
            $message = is_string($rawErrorMessage) ? $rawErrorMessage : 'Unknown Anthropic API error';

            return AiResponse::error($message);
        }

        return $this->parseResponse($data, $model);
    }

    /**
     * @param array{
     *     content?: list<array{type?: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}>,
     *     usage?: array{input_tokens?: int, output_tokens?: int},
     *     stop_reason?: string,
     * } $data
     */
    private function parseResponse(array $data, string $model): AiResponse
    {
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text' && isset($block['text'])) {
                $content .= $block['text'];
            } elseif ($type === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'] ?? '',
                    name: $block['name'] ?? '',
                    arguments: $block['input'] ?? [],
                );
            }
        }

        $stopReason = $data['stop_reason'] ?? 'end_turn';
        $finishReason = match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_use',
            default => $stopReason,
        };

        $usage = $data['usage'] ?? [];

        return new AiResponse(
            content: $content,
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $model,
        );
    }
}
