<?php

declare(strict_types=1);

namespace Pulsar\AI\Provider;

use Generator;
use Override;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\ChatRole;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Embedding\EmbeddingVector;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamAccumulator;
use Pulsar\AI\Streaming\AiStreamDelta;
use Pulsar\AI\Streaming\OllamaStreamParser;
use Pulsar\AI\Streaming\StreamContextTransport;
use Pulsar\AI\Streaming\StreamTransportInterface;
use Pulsar\AI\ToolCall;
use Pulsar\AI\ToolDefinition;
use Pulsar\Api\Internal;
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
     * @param StreamTransportInterface|null $streamTransport Transport for streamed calls; the default opens a socket
     * @param HttpClientInterface|null $httpClient Client for unstreamed calls; the default uses PHP's stream wrapper
     */
    public function __construct(
        string $model = 'llama3.1',
        private string $baseUrl = 'http://localhost:11434',
        private bool $allowLocalhost = true,
        private ?StreamTransportInterface $streamTransport = null,
        private ?HttpClientInterface $httpClient = null,
    ) {
        $this->defaultModel = $model;
    }

    #[Override]
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        $model = $options->model ?? $this->defaultModel;
        $payload = $this->buildChatPayload($messages, $options, $model);
        $payload['stream'] = false;

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
            toolCalls: $this->parseToolCalls($responseMessage),
            model: $model,
        );
    }

    #[Override]
    public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream
    {
        $model = $options->model ?? $this->defaultModel;
        $payload = $this->buildChatPayload($messages, $options, $model);
        $payload['stream'] = true;

        $url = rtrim($this->baseUrl, '/') . '/api/chat';

        // Validated here rather than inside the generator so an SSRF refusal is
        // raised by the call that asked for the stream, not by whoever happens
        // to iterate it later.
        $this->validateUrl($url);

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new AiStream(
            $this->readStream($url, $body, $model, $options->timeoutSeconds),
            'ollama',
        );
    }

    /**
     * Build the `/api/chat` request body shared by the streamed and unstreamed
     * paths, so the two cannot drift into asking for different things.
     *
     * The caller sets `stream`; everything else is identical either way.
     *
     * @param list<ChatMessage> $messages
     *
     * @return array<string, mixed>
     */
    private function buildChatPayload(array $messages, AiRequestOptions $options, string $model): array
    {
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
            'options' => array_filter([
                'temperature' => $options->temperature,
                'num_predict' => $options->maxTokens,
            ], static fn(mixed $v): bool => $v !== null),
        ]);

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
            $payload['format'] = 'json';
        }

        return $payload;
    }

    /**
     * Read `message.tool_calls` off an unstreamed `/api/chat` response.
     *
     * Ollama returns each call's arguments as a decoded object rather than as a
     * JSON string, and assigns no call id, so the id stays empty rather than
     * being invented. Without this the unstreamed path discarded tool calls the
     * model had actually made, which streaming would then have reported and the
     * two paths would have disagreed about the same bytes.
     *
     * @param array<string, mixed> $responseMessage
     *
     * @return list<ToolCall>
     */
    private function parseToolCalls(array $responseMessage): array
    {
        /** @var mixed $rawToolCalls */
        $rawToolCalls = $responseMessage['tool_calls'] ?? null;

        if (!is_array($rawToolCalls)) {
            return [];
        }

        $calls = [];

        /** @var mixed $entry */
        foreach ($rawToolCalls as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var mixed $rawFunction */
            $rawFunction = $entry['function'] ?? null;
            /** @var array<string, mixed> $function */
            $function = is_array($rawFunction) ? $rawFunction : [];

            /** @var mixed $rawName */
            $rawName = $function['name'] ?? null;
            /** @var mixed $rawArguments */
            $rawArguments = $function['arguments'] ?? null;
            /** @var array<string, mixed> $arguments */
            $arguments = is_array($rawArguments) ? $rawArguments : [];

            /** @var mixed $rawId */
            $rawId = $entry['id'] ?? null;

            $calls[] = new ToolCall(
                id: is_string($rawId) ? $rawId : '',
                name: is_string($rawName) ? $rawName : '',
                arguments: $arguments,
            );
        }

        return $calls;
    }

    /**
     * @return Generator<int, AiStreamDelta, mixed, AiResponse>
     *
     * @throws AiStreamException
     */
    private function readStream(string $url, string $body, string $model, int $idleTimeoutSeconds): Generator
    {
        $transport = $this->streamTransport ?? new StreamContextTransport('ollama');
        $accumulator = new AiStreamAccumulator('ollama', $model);
        $parser = new OllamaStreamParser();

        $chunks = $transport->postStream(
            $url,
            $body,
            ['Content-Type' => 'application/json'],
            $idleTimeoutSeconds,
        );

        try {
            foreach ($parser->deltas($chunks) as $delta) {
                $accumulator->accept($delta);

                yield $delta;
            }
        } catch (AiStreamException $failure) {
            // The transport knows the socket died; only the accumulator knows
            // what had already been produced. Re-throw with both.
            throw $failure->withPartialContent($accumulator->partialContent());
        }

        return $accumulator->finish();
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

            /** @var mixed $val */
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

        $url = rtrim($this->baseUrl, '/') . $path;
        $this->validateUrl($url);

        if ($this->httpClient !== null) {
            try {
                $response = $this->httpClient->post($url, [
                    'body' => $json,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => $timeout,
                ])->body();
            } catch (Throwable) {
                return null;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $json,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
            ]);

            $rawResponse = @file_get_contents($url, false, $context);

            if ($rawResponse === false) {
                return null;
            }

            $response = $rawResponse;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
