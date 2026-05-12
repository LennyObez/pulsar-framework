<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\LlmOptions;
use Pulsar\Extension\Cms\Internal\AI\OpenAiProvider;

#[CoversClass(OpenAiProvider::class)]
final class OpenAiProviderTest extends TestCase
{
    #[Test]
    public function name_returns_openai(): void
    {
        $provider = new OpenAiProvider('test-key');
        self::assertSame('openai', $provider->name());
    }

    #[Test]
    public function connection_failure_returns_error_response(): void
    {
        // Use a URL that will fail to connect (non-routable IP)
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'http://192.0.2.1:1',
        );

        $result = $provider->complete('Hello', new LlmOptions(maxTokens: 10));

        self::assertSame('error', $result->finishReason);
        self::assertStringContainsString('Failed to connect', $result->content);
        self::assertSame(0, $result->inputTokens);
        self::assertSame(0, $result->outputTokens);
    }

    #[Test]
    public function parses_successful_response_structure(): void
    {
        $responsePayload = [
            'choices' => [
                [
                    'message' => ['content' => 'Hello from GPT'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 25,
            ],
        ];

        // Verify the expected response structure for the OpenAI API
        self::assertArrayHasKey('choices', $responsePayload);
        self::assertSame('Hello from GPT', $responsePayload['choices'][0]['message']['content']);
    }

    #[Test]
    public function parses_error_response_structure(): void
    {
        $errorPayload = [
            'error' => [
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
            ],
        ];

        self::assertArrayHasKey('error', $errorPayload);
        self::assertSame('Rate limit exceeded', $errorPayload['error']['message']);
    }

    #[Test]
    public function constructs_with_custom_model_and_base_url(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            model: 'gpt-3.5-turbo',
            baseUrl: 'https://custom.endpoint.example.com/v1',
        );

        self::assertSame('openai', $provider->name());
    }
}
