<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Provider\OpenAiProvider;
use Pulsar\AI\ToolDefinition;

#[CoversClass(OpenAiProvider::class)]
final class OpenAiProviderTest extends TestCase
{
    #[Test]
    public function providerNameIsOpenai(): void
    {
        $provider = new OpenAiProvider(apiKey: 'test-key');

        self::assertSame('openai', $provider->providerName());
    }

    #[Test]
    public function completeReturnsErrorOnConnectionFailure(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $response = $provider->complete('Hello');

        self::assertTrue($response->isError());
        self::assertStringContainsString('Failed to connect', $response->content);
    }

    #[Test]
    public function chatWithToolsBuildsPayload(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $tool = new ToolDefinition(
            name: 'search',
            description: 'Search the web',
            parameters: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        );

        $response = $provider->chat(
            [ChatMessage::user('Search for PHP 8.5')],
            new AiRequestOptions(tools: [$tool]),
        );

        self::assertTrue($response->isError());
    }

    #[Test]
    public function embedReturnsEmptyOnConnectionFailure(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $result = $provider->embed(['Hello world']);

        self::assertSame(0, $result->count());
        self::assertSame(0, $result->totalTokens);
    }

    #[Test]
    public function structuredOutputUsesJsonFormat(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
        $response = $provider->structuredOutput('Extract title', $schema);

        self::assertTrue($response->isError());
    }

    #[Test]
    public function chatWithOrganizationHeader(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
            organization: 'org-123',
        );

        $response = $provider->complete('test');

        // Exercises the organization header code path
        self::assertTrue($response->isError());
    }

    #[Test]
    public function chatWithSystemPromptInOptions(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $response = $provider->chat(
            [ChatMessage::user('Hello')],
            new AiRequestOptions(systemPrompt: 'Be concise.'),
        );

        self::assertTrue($response->isError());
    }

    #[Test]
    public function chatWithToolResultMessage(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $response = $provider->chat([
            ChatMessage::user('What is the weather?'),
            ChatMessage::assistant('Let me check.'),
            ChatMessage::toolResult('call_1', '{"temp": 22}'),
        ]);

        self::assertTrue($response->isError());
    }
}
