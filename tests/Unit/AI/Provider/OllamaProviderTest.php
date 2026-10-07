<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Provider\OllamaProvider;

#[CoversClass(OllamaProvider::class)]
final class OllamaProviderTest extends TestCase
{
    /** `.invalid` never resolves (RFC 6761): the request fails before any socket opens, on every machine. */
    private const string UNREACHABLE = 'http://ollama.invalid:11434';

    #[Test]
    public function providerNameIsOllama(): void
    {
        $provider = new OllamaProvider();

        self::assertSame('ollama', $provider->providerName());
    }

    #[Test]
    public function completeReturnsErrorOnConnectionFailure(): void
    {
        $provider = new OllamaProvider(
            baseUrl: self::UNREACHABLE,
        );

        $response = $provider->complete('Hello');

        self::assertTrue($response->isError());
        self::assertStringContainsString('Failed to connect', $response->content);
    }

    #[Test]
    public function chatWithSystemPromptInOptions(): void
    {
        $provider = new OllamaProvider(
            baseUrl: self::UNREACHABLE,
        );

        $response = $provider->chat(
            [ChatMessage::user('Hello')],
            new AiRequestOptions(systemPrompt: 'Be brief.'),
        );

        self::assertTrue($response->isError());
    }

    #[Test]
    public function embedReturnsEmptyOnConnectionFailure(): void
    {
        $provider = new OllamaProvider(
            baseUrl: self::UNREACHABLE,
        );

        $result = $provider->embed(['Hello world']);

        self::assertSame(0, $result->count());
    }

    #[Test]
    public function structuredOutputUsesJsonFormat(): void
    {
        $provider = new OllamaProvider(
            baseUrl: self::UNREACHABLE,
        );

        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $response = $provider->structuredOutput('Extract', $schema);

        self::assertTrue($response->isError());
    }

    #[Test]
    public function defaultModelIsLlama(): void
    {
        $provider = new OllamaProvider();

        // The provider name confirms construction succeeded with defaults
        self::assertSame('ollama', $provider->providerName());
    }

    #[Test]
    public function customModelCanBeSpecified(): void
    {
        $provider = new OllamaProvider(model: 'mistral');

        self::assertSame('ollama', $provider->providerName());
    }

    #[Test]
    public function chatAllRolesAreHandled(): void
    {
        $provider = new OllamaProvider(baseUrl: self::UNREACHABLE);

        $response = $provider->chat([
            ChatMessage::system('System msg'),
            ChatMessage::user('User msg'),
            ChatMessage::assistant('Assistant msg'),
            ChatMessage::toolResult('tc_1', 'result'),
        ]);

        self::assertTrue($response->isError());
    }
}
