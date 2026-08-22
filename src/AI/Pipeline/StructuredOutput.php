<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use NoDiscard;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\Api\Api;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Structured output pipeline with JSON schema enforcement.
 *
 * Sends a prompt to the AI model with a JSON schema constraint,
 * then validates and parses the response into a typed array.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StructuredOutput
{
    public function __construct(
        private AiClientInterface $client,
    ) {}

    /**
     * Extract structured data from a prompt using a JSON schema.
     *
     * @param string $prompt The extraction prompt
     * @param array<string, mixed> $schema JSON Schema defining the expected output structure
     * @return StructuredOutputResult
     */
    #[NoDiscard]
    public function extract(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): StructuredOutputResult {
        $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $fullPrompt = $prompt . "\n\nRespond with valid JSON matching this schema:\n" . $schemaJson;

        $response = $this->client->structuredOutput($fullPrompt, $schema, $options);

        if ($response->isError()) {
            return new StructuredOutputResult(
                data: [],
                rawContent: $response->content,
                isValid: false,
                response: $response,
            );
        }

        $parsed = json_decode($response->content, true);

        if (!is_array($parsed)) {
            return new StructuredOutputResult(
                data: [],
                rawContent: $response->content,
                isValid: false,
                response: $response,
            );
        }

        /** @var array<string, mixed> $parsed */
        return new StructuredOutputResult(
            data: $parsed,
            rawContent: $response->content,
            isValid: true,
            response: $response,
        );
    }
}
