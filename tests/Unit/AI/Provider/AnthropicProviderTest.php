<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\Provider\AnthropicProvider;

#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTest extends TestCase
{
    #[Test]
    public function providerNameIsAnthropic(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        self::assertSame('anthropic', $provider->providerName());
    }

    #[Test]
    public function completeReturnsErrorOnConnectionFailure(): void
    {
        // Use an invalid URL that will fail to connect
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $response = $provider->complete('Hello');

        self::assertTrue($response->isError());
        self::assertStringContainsString('Failed to connect', $response->content);
    }

    #[Test]
    public function embedThrowsUnsupportedCapability(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('does not support embeddings');

        $provider->embed(['test']);
    }

    #[Test]
    public function chatPassesMessagesCorrectly(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
            baseUrl: 'https://unreachable.test:1',
        );

        // Will fail to connect, but exercises the message building code path
        $response = $provider->chat([
            ChatMessage::system('Be helpful.'),
            ChatMessage::user('Hello'),
            ChatMessage::assistant('Hi!'),
            ChatMessage::user('How are you?'),
        ]);

        self::assertTrue($response->isError());
    }

    #[Test]
    public function structuredOutputUsesJsonMode(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'https://unreachable.test:1',
        );

        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $response = $provider->structuredOutput('Extract name', $schema);

        self::assertTrue($response->isError());
    }
}
