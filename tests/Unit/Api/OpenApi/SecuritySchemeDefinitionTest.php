<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\SecuritySchemeDefinition;
use Pulsar\Api\OpenApi\SecuritySchemeType;

#[CoversClass(SecuritySchemeDefinition::class)]
final class SecuritySchemeDefinitionTest extends TestCase
{
    // --- Bearer factory ---

    #[Test]
    public function bearerFactoryDefaultValues(): void
    {
        $scheme = SecuritySchemeDefinition::bearer();

        self::assertSame('bearerAuth', $scheme->name);
        self::assertSame(SecuritySchemeType::Http, $scheme->type);
        self::assertSame('JWT Bearer token', $scheme->description);
        self::assertSame('bearer', $scheme->scheme);
        self::assertSame('JWT', $scheme->bearerFormat);
        self::assertNull($scheme->apiKeyName);
        self::assertNull($scheme->apiKeyIn);
        self::assertNull($scheme->flows);
    }

    #[Test]
    public function bearerFactoryCustomValues(): void
    {
        $scheme = SecuritySchemeDefinition::bearer(
            name: 'customBearer',
            description: 'Custom bearer description',
        );

        self::assertSame('customBearer', $scheme->name);
        self::assertSame('Custom bearer description', $scheme->description);
    }

    #[Test]
    public function bearerToOpenApiProducesHttpScheme(): void
    {
        $scheme = SecuritySchemeDefinition::bearer();
        $openApi = $scheme->toOpenApi();

        self::assertSame('http', $openApi['type']);
        self::assertSame('bearer', $openApi['scheme']);
        self::assertSame('JWT', $openApi['bearerFormat']);
        self::assertSame('JWT Bearer token', $openApi['description']);
    }

    // --- API Key factory ---

    #[Test]
    public function apiKeyFactoryDefaultValues(): void
    {
        $scheme = SecuritySchemeDefinition::apiKey();

        self::assertSame('apiKeyAuth', $scheme->name);
        self::assertSame(SecuritySchemeType::ApiKey, $scheme->type);
        self::assertSame('API key authentication', $scheme->description);
        self::assertSame('X-API-Key', $scheme->apiKeyName);
        self::assertSame('header', $scheme->apiKeyIn);
        self::assertNull($scheme->scheme);
        self::assertNull($scheme->bearerFormat);
    }

    #[Test]
    public function apiKeyFactoryCustomValues(): void
    {
        $scheme = SecuritySchemeDefinition::apiKey(
            name: 'tokenAuth',
            headerName: 'Authorization',
            in: 'query',
            description: 'Token-based auth',
        );

        self::assertSame('tokenAuth', $scheme->name);
        self::assertSame('Authorization', $scheme->apiKeyName);
        self::assertSame('query', $scheme->apiKeyIn);
        self::assertSame('Token-based auth', $scheme->description);
    }

    #[Test]
    public function apiKeyToOpenApiProducesApiKeyScheme(): void
    {
        $scheme = SecuritySchemeDefinition::apiKey(
            headerName: 'X-Custom-Key',
            in: 'cookie',
        );
        $openApi = $scheme->toOpenApi();

        self::assertSame('apiKey', $openApi['type']);
        self::assertSame('X-Custom-Key', $openApi['name']);
        self::assertSame('cookie', $openApi['in']);
        self::assertSame('API key authentication', $openApi['description']);
    }

    // --- OAuth2 factory ---

    #[Test]
    public function oauth2FactoryDefaultValues(): void
    {
        $scheme = SecuritySchemeDefinition::oauth2(
            authorizationUrl: 'https://auth.example.com/authorize',
            tokenUrl: 'https://auth.example.com/token',
        );

        self::assertSame('oauth2', $scheme->name);
        self::assertSame(SecuritySchemeType::OAuth2, $scheme->type);
        self::assertSame('OAuth 2.0 authorization code flow', $scheme->description);
        self::assertNotNull($scheme->flows);
        self::assertArrayHasKey('authorizationCode', $scheme->flows);
    }

    #[Test]
    public function oauth2FactoryWithScopes(): void
    {
        $scopes = ['read:users' => 'Read user data', 'write:users' => 'Modify user data'];
        $scheme = SecuritySchemeDefinition::oauth2(
            authorizationUrl: 'https://auth.example.com/authorize',
            tokenUrl: 'https://auth.example.com/token',
            scopes: $scopes,
            name: 'customOAuth',
            description: 'Custom OAuth',
        );

        self::assertSame('customOAuth', $scheme->name);
        self::assertSame('Custom OAuth', $scheme->description);

        self::assertIsArray($scheme->flows);
        self::assertArrayHasKey('authorizationCode', $scheme->flows);
        $flow = $scheme->flows['authorizationCode'];
        assert(is_array($flow));
        self::assertSame('https://auth.example.com/authorize', $flow['authorizationUrl']);
        self::assertSame('https://auth.example.com/token', $flow['tokenUrl']);
        self::assertSame($scopes, $flow['scopes']);
    }

    #[Test]
    public function oauth2ToOpenApiProducesOAuth2Scheme(): void
    {
        $scheme = SecuritySchemeDefinition::oauth2(
            authorizationUrl: 'https://example.com/auth',
            tokenUrl: 'https://example.com/token',
            scopes: ['read' => 'Read access'],
        );
        $openApi = $scheme->toOpenApi();

        self::assertSame('oauth2', $openApi['type']);
        self::assertArrayHasKey('flows', $openApi);
        $flows = $openApi['flows'];
        assert(is_array($flows));
        $authCodeFlow = $flows['authorizationCode'];
        assert(is_array($authCodeFlow));
        self::assertSame('https://example.com/auth', $authCodeFlow['authorizationUrl']);
        self::assertSame('https://example.com/token', $authCodeFlow['tokenUrl']);
        self::assertSame(['read' => 'Read access'], $authCodeFlow['scopes']);
    }

    // --- OpenIdConnect ---

    #[Test]
    public function openIdConnectToOpenApiProducesMinimalScheme(): void
    {
        $scheme = new SecuritySchemeDefinition(
            name: 'oidc',
            type: SecuritySchemeType::OpenIdConnect,
            description: 'OpenID Connect',
        );
        $openApi = $scheme->toOpenApi();

        self::assertSame('openIdConnect', $openApi['type']);
        self::assertSame('OpenID Connect', $openApi['description']);
        self::assertArrayNotHasKey('scheme', $openApi);
        self::assertArrayNotHasKey('name', $openApi);
        self::assertArrayNotHasKey('flows', $openApi);
    }

    // --- Edge cases ---

    #[Test]
    public function emptyDescriptionIsOmittedFromOpenApi(): void
    {
        $scheme = new SecuritySchemeDefinition(
            name: 'minimal',
            type: SecuritySchemeType::Http,
            description: '',
        );
        $openApi = $scheme->toOpenApi();

        self::assertArrayNotHasKey('description', $openApi);
        self::assertSame('http', $openApi['type']);
    }

    #[Test]
    public function httpSchemeWithoutSchemeOrBearerFormatOmitsThem(): void
    {
        $scheme = new SecuritySchemeDefinition(
            name: 'basic',
            type: SecuritySchemeType::Http,
            description: 'Basic auth',
        );
        $openApi = $scheme->toOpenApi();

        self::assertSame('http', $openApi['type']);
        self::assertSame('Basic auth', $openApi['description']);
        self::assertArrayNotHasKey('scheme', $openApi);
        self::assertArrayNotHasKey('bearerFormat', $openApi);
    }

    #[Test]
    public function apiKeySchemeWithoutNameOrInOmitsThem(): void
    {
        $scheme = new SecuritySchemeDefinition(
            name: 'emptyApiKey',
            type: SecuritySchemeType::ApiKey,
            description: 'Minimal API key',
        );
        $openApi = $scheme->toOpenApi();

        self::assertSame('apiKey', $openApi['type']);
        self::assertArrayNotHasKey('name', $openApi);
        self::assertArrayNotHasKey('in', $openApi);
    }

    #[Test]
    public function oauth2SchemeWithoutFlowsOmitsThem(): void
    {
        $scheme = new SecuritySchemeDefinition(
            name: 'noFlows',
            type: SecuritySchemeType::OAuth2,
            description: 'OAuth2 without flows',
        );
        $openApi = $scheme->toOpenApi();

        self::assertSame('oauth2', $openApi['type']);
        self::assertArrayNotHasKey('flows', $openApi);
    }

    // --- Constructor direct ---

    #[Test]
    public function constructorSetsAllPropertiesDirectly(): void
    {
        $flows = ['authorizationCode' => ['authorizationUrl' => 'https://x.com/auth', 'tokenUrl' => 'https://x.com/token', 'scopes' => []]];
        $scheme = new SecuritySchemeDefinition(
            name: 'full',
            type: SecuritySchemeType::OAuth2,
            description: 'Full scheme',
            scheme: 'bearer',
            bearerFormat: 'JWT',
            apiKeyName: 'X-Key',
            apiKeyIn: 'header',
            flows: $flows,
        );

        self::assertSame('full', $scheme->name);
        self::assertSame(SecuritySchemeType::OAuth2, $scheme->type);
        self::assertSame('Full scheme', $scheme->description);
        self::assertSame('bearer', $scheme->scheme);
        self::assertSame('JWT', $scheme->bearerFormat);
        self::assertSame('X-Key', $scheme->apiKeyName);
        self::assertSame('header', $scheme->apiKeyIn);
        self::assertSame($flows, $scheme->flows);
    }

    // --- Type values ---

    #[Test]
    #[DataProvider('schemeTypeValueProvider')]
    public function securitySchemeTypeHasExpectedValue(SecuritySchemeType $type, string $expectedValue): void
    {
        self::assertSame($expectedValue, $type->value);
    }

    /**
     * @return iterable<string, array{SecuritySchemeType, string}>
     */
    public static function schemeTypeValueProvider(): iterable
    {
        yield 'http' => [SecuritySchemeType::Http, 'http'];
        yield 'apiKey' => [SecuritySchemeType::ApiKey, 'apiKey'];
        yield 'oauth2' => [SecuritySchemeType::OAuth2, 'oauth2'];
        yield 'openIdConnect' => [SecuritySchemeType::OpenIdConnect, 'openIdConnect'];
    }
}
