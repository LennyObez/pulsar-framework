<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\Provider\AnthropicProvider;
use Pulsar\AI\Provider\OllamaProvider;
use Pulsar\AI\Provider\OpenAiProvider;

/**
 * Tests SSRF protection (CWE-918) across all AI providers.
 *
 * Validates that:
 * - Private/reserved IPv4 addresses are rejected
 * - Private/reserved IPv6 addresses are rejected
 * - Cloud metadata endpoints are always blocked
 * - HTTPS is enforced for cloud providers (OpenAI, Anthropic)
 * - Ollama allows localhost when allowLocalhost=true, rejects it when false
 */
#[CoversClass(OpenAiProvider::class)]
#[CoversClass(AnthropicProvider::class)]
#[CoversClass(OllamaProvider::class)]
final class ProviderUrlValidationTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  OpenAI: must reject private IPs
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('privateIpBaseUrls')]
    public function openAiRejectsPrivateIpBaseUrl(string $baseUrl): void
    {
        // Arrange
        $provider = new OpenAiProvider(apiKey: 'test-key', baseUrl: $baseUrl);

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function openAiRejectsNonHttpScheme(): void
    {
        // Arrange — the scheme is ftp, not http/https
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'ftp://evil.example.com/v1',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function openAiAllowsValidHttpsEndpoint(): void
    {
        // Arrange — valid HTTPS URL; will fail connection but should pass SSRF check
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://api.openai.com/v1',
        );

        // Act — connection will fail, but SSRF check should pass (no exception)
        $response = $provider->complete('Hello');

        // Assert — error response from connection failure, NOT SSRF block
        self::assertTrue($response->isError());
        self::assertStringNotContainsString('SSRF', $response->content);
    }

    #[Test]
    public function openAiRejectsCloudMetadataEndpoint(): void
    {
        // Arrange — AWS metadata endpoint
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'http://169.254.169.254',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function openAiEmbedRejectsPrivateIp(): void
    {
        // Arrange
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'http://10.0.0.1/v1',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->embed(['Hello']);
    }

    // -----------------------------------------------------------------------
    //  Anthropic: must reject private IPs
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('privateIpBaseUrls')]
    public function anthropicRejectsPrivateIpBaseUrl(string $baseUrl): void
    {
        // Arrange
        $provider = new AnthropicProvider(apiKey: 'test-key', baseUrl: $baseUrl);

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function anthropicRejectsNonHttpScheme(): void
    {
        // Arrange — the scheme is ftp, not http/https
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'ftp://evil.example.com/v1',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function anthropicAllowsValidHttpsEndpoint(): void
    {
        // Arrange
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'https://api.anthropic.com/v1',
        );

        // Act
        $response = $provider->complete('Hello');

        // Assert — connection error but NOT SSRF block
        self::assertTrue($response->isError());
        self::assertStringNotContainsString('SSRF', $response->content);
    }

    #[Test]
    public function anthropicRejectsCloudMetadataEndpoint(): void
    {
        // Arrange
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'http://169.254.169.254',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    // -----------------------------------------------------------------------
    //  Ollama: localhost behavior controlled by allowLocalhost flag
    // -----------------------------------------------------------------------

    #[Test]
    public function ollamaAllowsLocalhostByDefault(): void
    {
        // Arrange — default allowLocalhost=true
        $provider = new OllamaProvider(
            baseUrl: 'http://127.0.0.1:11434',
        );

        // Act — will fail connection but should NOT throw SSRF exception
        $response = $provider->complete('Hello');

        // Assert
        self::assertTrue($response->isError());
        self::assertStringContainsString('Failed to connect', $response->content);
    }

    #[Test]
    public function ollamaAllowsLocalhostNameByDefault(): void
    {
        // Arrange — using "localhost" hostname
        $provider = new OllamaProvider(
            baseUrl: 'http://localhost:11434',
        );

        // Act
        $response = $provider->complete('Hello');

        // Assert
        self::assertTrue($response->isError());
        self::assertStringNotContainsString('SSRF', $response->content);
    }

    #[Test]
    public function ollamaRejectsLocalhostWhenAllowLocalhostDisabled(): void
    {
        // Arrange
        $provider = new OllamaProvider(
            baseUrl: 'http://127.0.0.1:11434',
            allowLocalhost: false,
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function ollamaRejectsHttpForRemoteWhenAllowLocalhostDisabled(): void
    {
        // Arrange — valid public IP but HTTP scheme
        $provider = new OllamaProvider(
            baseUrl: 'http://203.0.113.5:11434',
            allowLocalhost: false,
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked.*HTTPS/');

        $provider->complete('Hello');
    }

    #[Test]
    public function ollamaAlwaysBlocksCloudMetadata(): void
    {
        // Arrange — even with allowLocalhost=true, metadata IPs are blocked
        $provider = new OllamaProvider(
            baseUrl: 'http://169.254.169.254',
            allowLocalhost: true,
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function ollamaAllowsPrivateNetworkWhenAllowLocalhostEnabled(): void
    {
        // Arrange — 10.x.x.x is private but allowLocalhost allows all private networks
        $provider = new OllamaProvider(
            baseUrl: 'http://10.0.0.5:11434',
            allowLocalhost: true,
        );

        // Act
        $response = $provider->complete('Hello');

        // Assert — connection error but NOT SSRF block
        self::assertTrue($response->isError());
        self::assertStringNotContainsString('SSRF', $response->content);
    }

    #[Test]
    #[DataProvider('privateIpBaseUrls')]
    public function ollamaRejectsPrivateIpWhenAllowLocalhostDisabled(string $baseUrl): void
    {
        // Arrange
        $provider = new OllamaProvider(
            baseUrl: $baseUrl,
            allowLocalhost: false,
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    // -----------------------------------------------------------------------
    //  IPv6 coverage
    // -----------------------------------------------------------------------

    #[Test]
    public function openAiRejectsIpv6Loopback(): void
    {
        // Arrange
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'http://[::1]:8080/v1',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    #[Test]
    public function openAiRejectsIpv6UniqueLocal(): void
    {
        // Arrange — fc00::/7 range
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'http://[fd12::1]:8080/v1',
        );

        // Act & Assert
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/SSRF protection blocked/');

        $provider->complete('Hello');
    }

    // -----------------------------------------------------------------------
    //  Exception message quality
    // -----------------------------------------------------------------------

    #[Test]
    public function ssrfExceptionIncludesProviderName(): void
    {
        // Arrange
        $provider = new OpenAiProvider(
            apiKey: 'test-key',
            baseUrl: 'http://192.168.1.1/v1',
        );

        // Act & Assert
        try {
            $provider->complete('Hello');
            self::fail('Expected AiException was not thrown');
        } catch (AiException $e) {
            self::assertStringContainsString('openai', $e->getMessage());
            self::assertStringContainsString('192.168.1.1', $e->getMessage());
        }
    }

    #[Test]
    public function ssrfExceptionIncludesAnthropicProviderName(): void
    {
        // Arrange
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'http://10.0.0.1/v1',
        );

        // Act & Assert
        try {
            $provider->complete('Hello');
            self::fail('Expected AiException was not thrown');
        } catch (AiException $e) {
            self::assertStringContainsString('anthropic', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    //  Data providers
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateIpBaseUrls(): iterable
    {
        yield '127.0.0.1 (loopback)' => ['http://127.0.0.1:8080/v1'];
        yield '10.0.0.1 (RFC1918 class A)' => ['http://10.0.0.1:8080/v1'];
        yield '172.16.0.1 (RFC1918 class B)' => ['http://172.16.0.1:8080/v1'];
        yield '192.168.1.1 (RFC1918 class C)' => ['http://192.168.1.1:8080/v1'];
        yield '169.254.0.1 (link-local)' => ['http://169.254.0.1:8080/v1'];
    }
}
