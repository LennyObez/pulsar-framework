<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Config\ProviderCredentials;

#[CoversClass(ProviderCredentials::class)]
final class ProviderCredentialsTest extends TestCase
{
    #[Test]
    public function defaultsAreEmptyStrings(): void
    {
        $creds = new ProviderCredentials();

        self::assertSame('', $creds->apiKey);
        self::assertSame('', $creds->baseUrl);
        self::assertSame('', $creds->organization);
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $creds = new ProviderCredentials(
            apiKey: 'sk-test-key-123',
            baseUrl: 'https://api.example.com/v1',
            organization: 'org-abc',
        );

        self::assertSame('sk-test-key-123', $creds->apiKey);
        self::assertSame('https://api.example.com/v1', $creds->baseUrl);
        self::assertSame('org-abc', $creds->organization);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $creds = ProviderCredentials::fromArray([
            'api_key' => 'sk-real-key',
            'base_url' => 'https://api.openai.com/v1',
            'organization' => 'org-pulsar',
        ]);

        self::assertSame('sk-real-key', $creds->apiKey);
        self::assertSame('https://api.openai.com/v1', $creds->baseUrl);
        self::assertSame('org-pulsar', $creds->organization);
    }

    #[Test]
    public function fromArrayDefaultsMissingFieldsToEmptyString(): void
    {
        $creds = ProviderCredentials::fromArray([]);

        self::assertSame('', $creds->apiKey);
        self::assertSame('', $creds->baseUrl);
        self::assertSame('', $creds->organization);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $creds = ProviderCredentials::fromArray([
            'api_key' => 12345,
            'base_url' => false,
            'organization' => ['nested'],
        ]);

        self::assertSame('', $creds->apiKey);
        self::assertSame('', $creds->baseUrl);
        self::assertSame('', $creds->organization);
    }

    #[Test]
    public function fromArrayIgnoresNullValues(): void
    {
        $creds = ProviderCredentials::fromArray([
            'api_key' => null,
            'base_url' => null,
            'organization' => null,
        ]);

        self::assertSame('', $creds->apiKey);
        self::assertSame('', $creds->baseUrl);
        self::assertSame('', $creds->organization);
    }

    #[Test]
    public function fromArrayIgnoresExtraKeys(): void
    {
        $creds = ProviderCredentials::fromArray([
            'api_key' => 'key123',
            'unknown_field' => 'value',
            'another' => 42,
        ]);

        self::assertSame('key123', $creds->apiKey);
        self::assertSame('', $creds->baseUrl);
    }
}
