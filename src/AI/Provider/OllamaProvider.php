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
use Pulsar\Api\Internal;
use Pulsar\Security\Validation\UrlSafetyValidator;

use function array_filter;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_url;
use function rtrim;
use function stream_context_create;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Ollama local model provider.
 *
 * Connects to a local Ollama instance for chat, completion, and embedding
 * without sending data to external APIs.
 */
#[Internal(reason: 'Provider implementation; use AiClientInterface')]
final readonly class OllamaProvider implements AiClientInterface
{
    private string $defaultModel;

    /**
     * @param string $model       Default model name
     * @param string $baseUrl     Ollama API base URL (typically http://localhost:11434)
     * @param bool   $allowLocalhost Whether to allow localhost/private IPs (true for local Ollama)
     */
    public function __construct(
        string $model = 'llama3.1',
        private string $baseUrl = 'http://localhost:11434',
        private bool $allowLocalhost = true,
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

            $apiMessages[] = ['role' => $role, 'content' => $msg->content];
        }

        $payload = array_filter([
            'model' => $model,
            'messages' => $apiMessages,
            'stream' => false,
            'options' => array_filter([
                'temperature' => $options->temperature,
                'num_predict' => $options->maxTokens,
            ], static fn(mixed $v): bool => $v !== null),
        ]);

        if ($options->responseFormat === 'json') {
            $payload['format'] = 'json';
        }

        $data = $this->sendRequest('/api/chat', $payload, $options->timeoutSeconds);

        if ($data === null) {
            return AiResponse::error('Failed to connect to Ollama');
        }

        if (isset($data['error'])) {
            $message = is_string($data['error']) ? $data['error'] : 'Unknown Ollama error';

            return AiResponse::error($message);
        }

        /** @var mixed $rawMessage */
        $rawMessage = $data['message'] ?? null;
        /** @var array{content?: string} $responseMessage */
        $responseMessage = is_array($rawMessage) ? $rawMessage : [];

        /** @var mixed $rawEvalCount */
        $rawEvalCount = $data['eval_count'] ?? null;
        $evalCount = is_int($rawEvalCount) ? $rawEvalCount : 0;
        /** @var mixed $rawPromptEvalCount */
        $rawPromptEvalCount = $data['prompt_eval_count'] ?? null;
        $promptEvalCount = is_int($rawPromptEvalCount) ? $rawPromptEvalCount : 0;

        $rawContent = $responseMessage['content'] ?? null;
        /** @var mixed $rawDoneReason */
        $rawDoneReason = $data['done_reason'] ?? null;
        return new AiResponse(
            content: is_string($rawContent) ? $rawContent : '',
            inputTokens: $promptEvalCount,
            outputTokens: $evalCount,
            finishReason: is_string($rawDoneReason) ? $rawDoneReason : 'stop',
            model: $model,
        );
    }

    #[Override]
    public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        return $this->chat([ChatMessage::user($prompt)], $options);
    }

    #[Override]
    public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): EmbeddingResult
    {
        $model = $options->model ?? 'nomic-embed-text';

        $payload = [
            'model' => $model,
            'input' => $inputs,
        ];

        $data = $this->sendRequest('/api/embed', $payload, $options->timeoutSeconds);

        if ($data === null || isset($data['error'])) {
            return new EmbeddingResult(embeddings: [], totalTokens: 0, model: $model);
        }

        /** @var list<mixed> $embeddings */
        $embeddings = is_array($data['embeddings'] ?? null) ? $data['embeddings'] : [];

        $vectors = [];

        foreach ($embeddings as $index => $values) {
            if (!is_array($values)) {
                continue;
            }

            $floats = [];

            foreach ($values as $val) {
                if (is_float($val) || is_int($val)) {
                    $floats[] = (float) $val;
                }
            }

            $vectors[] = new EmbeddingVector(values: $floats, index: $index);
        }

        return new EmbeddingResult(
            embeddings: $vectors,
            totalTokens: 0,
            model: $model,
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
                systemPrompt: $options->systemPrompt ?? 'Respond with valid JSON only.',
                model: $options->model,
                timeoutSeconds: $options->timeoutSeconds,
                responseFormat: 'json',
            ),
        );
    }

    #[Override]
    public function providerName(): string
    {
        return 'ollama';
    }

    /**
     * Validate the outbound URL is safe against SSRF (CWE-918).
     *
     * Ollama typically runs on localhost so private networks are allowed
     * when `allowLocalhost` is true. Cloud metadata endpoints are always blocked.
     * When `allowLocalhost` is false, standard SSRF rules apply (HTTPS required,
     * no private IPs).
     *
     * @throws AiException When the URL targets a blocked address
     */
    private function validateUrl(string $url): void
    {
        $result = UrlSafetyValidator::validate($url, allowPrivateNetworks: $this->allowLocalhost);

        if (!$result->safe) {
            throw AiException::ssrfBlocked('ollama', $url, $result->reason);
        }

        // When allowLocalhost is false, also enforce HTTPS
        if (!$this->allowLocalhost) {
            $scheme = parse_url($url, PHP_URL_SCHEME);

            if ($scheme !== 'https') {
                throw AiException::ssrfBlocked(
                    'ollama',
                    $url,
                    'Non-localhost Ollama instances must use HTTPS',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     *
     * @throws AiException When the URL fails SSRF validation
     */
    private function sendRequest(string $path, array $payload, int $timeout): ?array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $url = rtrim($this->baseUrl, '/') . $path;
        $this->validateUrl($url);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
