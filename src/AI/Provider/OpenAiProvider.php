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
use Pulsar\AI\Embedding\EmbeddingVector;
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
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function stream_context_create;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * OpenAI-compatible API provider.
 *
 * Works with OpenAI, Azure OpenAI, and any API that follows the
 * OpenAI chat completions format.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OpenAiProvider implements AiClientInterface
{
    private string $defaultModel;

    public function __construct(
        private string $apiKey,
        string $model = 'gpt-4o',
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $organization = '',
        private ?HttpClientInterface $httpClient = null,
    ) {
        $this->defaultModel = $model;
    }

    #[Override]
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        $model = $options->model ?? $this->defaultModel;
        $apiMessages = [];

        if ($options->systemPrompt !== null) {
            $apiMessages[] = ['role' => 'system', 'content' => $options->systemPrompt];
        }

        foreach ($messages as $msg) {
            $role = match ($msg->role) {
                ChatRole::System => 'system',
                ChatRole::User => 'user',
                ChatRole::Assistant => 'assistant',
                ChatRole::Tool => 'tool',
            };

            $entry = ['role' => $role, 'content' => $msg->content];

            if ($msg->toolCallId !== null) {
                $entry['tool_call_id'] = $msg->toolCallId;
            }

            if ($msg->name !== null) {
                $entry['name'] = $msg->name;
            }

            $apiMessages[] = $entry;
        }

        $payload = array_filter([
            'model' => $model,
            'messages' => $apiMessages,
            'temperature' => $options->temperature,
            'max_tokens' => $options->maxTokens,
        ], static fn(mixed $v): bool => $v !== null);

        if ($options->tools !== []) {
            $payload['tools'] = array_map(
                static fn(ToolDefinition $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->parameters,
                    ],
                ],
                $options->tools,
            );
        }

        if ($options->responseFormat === 'json') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $this->sendChatRequest($payload, $model, $options->timeoutSeconds);
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
        $model = $options->model ?? 'text-embedding-3-small';

        $payload = [
            'model' => $model,
            'input' => $inputs,
        ];

        $url = rtrim($this->baseUrl, '/') . '/embeddings';
        $response = $this->doPost($url, $payload, $options->timeoutSeconds);

        if ($response === null) {
            return new EmbeddingResult(embeddings: [], totalTokens: 0, model: $model);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || isset($decoded['error'])) {
            return new EmbeddingResult(embeddings: [], totalTokens: 0, model: $model);
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        /** @var list<array{embedding?: list<float>, index?: int}> $dataItems */
        $dataItems = is_array($data['data'] ?? null) ? $data['data'] : [];

        /** @var array{total_tokens?: int} $usage */
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $vectors = [];

        foreach ($dataItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rawEmbedding = $item['embedding'] ?? null;
            /** @var list<mixed> $embeddingValues */
            $embeddingValues = is_array($rawEmbedding) ? $rawEmbedding : [];

            $floats = [];

            foreach ($embeddingValues as $val) {
                if (is_float($val) || is_int($val)) {
                    $floats[] = (float) $val;
                }
            }

            $rawIndex = $item['index'] ?? null;
            $vectors[] = new EmbeddingVector(
                values: $floats,
                index: is_int($rawIndex) ? $rawIndex : 0,
            );
        }

        $rawTotalTokens = $usage['total_tokens'] ?? null;
        return new EmbeddingResult(
            embeddings: $vectors,
            totalTokens: is_int($rawTotalTokens) ? $rawTotalTokens : 0,
            model: $model,
        );
    }

    #[Override]
    public function structuredOutput(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): AiResponse {
        return $this->chat(
            [ChatMessage::user($prompt)],
            new AiRequestOptions(
                temperature: $options->temperature ?? 0.0,
                maxTokens: $options->maxTokens,
                systemPrompt: $options->systemPrompt ?? 'You are a structured data extraction assistant. Always respond with valid JSON matching the provided schema.',
                model: $options->model,
                timeoutSeconds: $options->timeoutSeconds,
                responseFormat: 'json',
            ),
        );
    }

    #[Override]
    public function providerName(): string
    {
        return 'openai';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendChatRequest(array $payload, string $model, int $timeout): AiResponse
    {
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $responseBody = $this->doPost($url, $payload, $timeout);

        if ($responseBody === null) {
            return AiResponse::error('Failed to connect to OpenAI API');
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            return AiResponse::error('Invalid JSON response from OpenAI API');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        if (isset($data['error'])) {
            /** @var array{message?: string} $error */
            $error = (array) $data['error'];
            $rawErrorMessage = $error['message'] ?? null;
            $message = is_string($rawErrorMessage) ? $rawErrorMessage : 'Unknown OpenAI API error';

            return AiResponse::error($message);
        }

        return $this->parseChatResponse($data, $model);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseChatResponse(array $data, string $model): AiResponse
    {
        $rawChoices = $data['choices'] ?? null;
        /** @var list<array<string, mixed>> $choices */
        $choices = is_array($rawChoices) ? $rawChoices : [];
        $rawFirstChoice = $choices[0] ?? null;
        $firstChoice = is_array($rawFirstChoice) ? $rawFirstChoice : [];

        $rawMessage = $firstChoice['message'] ?? null;
        /** @var array{content?: string, tool_calls?: list<array<string, mixed>>} $message */
        $message = is_array($rawMessage) ? $rawMessage : [];

        $rawUsage = $data['usage'] ?? null;
        /** @var array{prompt_tokens?: int, completion_tokens?: int} $usage */
        $usage = is_array($rawUsage) ? $rawUsage : [];

        $rawContent = $message['content'] ?? null;
        $content = is_string($rawContent) ? $rawContent : '';

        /** @var list<ToolCall> $toolCalls */
        $toolCalls = [];

        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                if (!is_array($tc)) {
                    continue;
                }

                /** @var array{id?: string, function?: array{name?: string, arguments?: string}} $tc */
                $rawFunc = $tc['function'] ?? null;
                $func = is_array($rawFunc) ? $rawFunc : [];
                $rawArgsStr = $func['arguments'] ?? null;
                $argsStr = is_string($rawArgsStr) ? $rawArgsStr : '{}';

                $argsDecoded = json_decode($argsStr, true);
                /** @var array<string, mixed> $args */
                $args = is_array($argsDecoded) ? $argsDecoded : [];

                $toolCalls[] = new ToolCall(
                    id: is_string($tc['id'] ?? null) ? $tc['id'] : '',
                    name: is_string($func['name'] ?? null) ? $func['name'] : '',
                    arguments: $args,
                );
            }
        }

        $finishReason = is_string($firstChoice['finish_reason'] ?? null) ? $firstChoice['finish_reason'] : 'stop';

        return new AiResponse(
            content: $content,
            inputTokens: is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            outputTokens: is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $model,
        );
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
            throw AiException::ssrfBlocked('openai', $url, $result->reason);
        }
    }

    /**
     * Send a POST request using HttpClient (preferred) or stream_context fallback.
     *
     * @param array<string, mixed> $payload JSON-serializable payload
     *
     * @return string|null Response body or null on connection failure
     *
     * @throws AiException When the URL fails SSRF validation
     */
    private function doPost(string $url, array $payload, int $timeout): ?string
    {
        $this->validateUrl($url);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        if ($this->organization !== '') {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        if ($this->httpClient !== null) {
            try {
                $httpResponse = $this->httpClient->post($url, [
                    'json' => $payload,
                    'headers' => $headers,
                    'timeout' => $timeout,
                ]);

                return $httpResponse->body();
            } catch (Throwable) {
                return null;
            }
        }

        // Fallback: stream_context_create for environments without HttpClient
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headerStr = "Content-Type: application/json\r\n"
            . "Authorization: Bearer $this->apiKey\r\n";

        if ($this->organization !== '') {
            $headerStr .= "OpenAI-Organization: $this->organization\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerStr,
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }
}
