<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\LlmOptions;
use Pulsar\Extension\Cms\Internal\AI\AnthropicProvider;

#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTest extends TestCase
{
    #[Test]
    public function name_returns_anthropic(): void
    {
        $provider = new AnthropicProvider('test-key');
        self::assertSame('anthropic', $provider->name());
    }

    #[Test]
    public function connection_failure_returns_error_response(): void
    {
        // Use a URL that will fail to connect (non-routable IP)
        $provider = new AnthropicProvider(
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
            'content' => [
                ['type' => 'text', 'text' => 'Hello from Claude'],
            ],
            'usage' => [
                'input_tokens' => 20,
                'output_tokens' => 30,
            ],
            'stop_reason' => 'end_turn',
        ];

        self::assertArrayHasKey('content', $responsePayload);
        self::assertSame('Hello from Claude', $responsePayload['content'][0]['text']);
        self::assertSame('end_turn', $responsePayload['stop_reason']);
    }

    #[Test]
    public function maps_stop_reasons_correctly(): void
    {
        // Anthropic's "end_turn" maps to "stop"
        // Anthropic's "max_tokens" maps to "length"
        $mapping = [
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop_sequence',
        ];

        foreach ($mapping as $anthropicReason => $expected) {
            $finishReason = match ($anthropicReason) {
                'end_turn' => 'stop',
                'max_tokens' => 'length',
                default => $anthropicReason,
            };

            self::assertSame($expected, $finishReason, "Stop reason '{$anthropicReason}' should map to '{$expected}'");
        }
    }

    #[Test]
    public function constructs_with_custom_model_and_base_url(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-opus-4-6',
            baseUrl: 'https://custom.anthropic.example.com/v1',
        );

        self::assertSame('anthropic', $provider->name());
    }
}
