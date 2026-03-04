<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Smart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Smart\SmartConfiguration;

#[CoversClass(SmartConfiguration::class)]
final class SmartConfigurationTest extends TestCase
{
    #[Test]
    public function toArrayMinimalConfiguration(): void
    {
        $config = new SmartConfiguration(
            issuer: 'https://fhir.example.com',
            authorizationEndpoint: 'https://fhir.example.com/auth',
            tokenEndpoint: 'https://fhir.example.com/token',
        );

        $array = $config->toArray();

        self::assertSame('https://fhir.example.com', $array['issuer']);
        self::assertSame('https://fhir.example.com/auth', $array['authorization_endpoint']);
        self::assertSame('https://fhir.example.com/token', $array['token_endpoint']);
        self::assertSame(['code'], $array['response_types_supported']);
        self::assertSame(['authorization_code'], $array['grant_types_supported']);
        self::assertSame(['S256'], $array['code_challenge_methods_supported']);
        self::assertArrayNotHasKey('registration_endpoint', $array);
        self::assertArrayNotHasKey('management_endpoint', $array);
        self::assertArrayNotHasKey('introspection_endpoint', $array);
        self::assertArrayNotHasKey('revocation_endpoint', $array);
        self::assertArrayNotHasKey('scopes_supported', $array);
        self::assertArrayNotHasKey('capabilities', $array);
    }

    #[Test]
    public function toArrayFullConfiguration(): void
    {
        $config = new SmartConfiguration(
            issuer: 'https://fhir.example.com',
            authorizationEndpoint: 'https://fhir.example.com/auth',
            tokenEndpoint: 'https://fhir.example.com/token',
            registrationEndpoint: 'https://fhir.example.com/register',
            managementEndpoint: 'https://fhir.example.com/manage',
            introspectionEndpoint: 'https://fhir.example.com/introspect',
            revocationEndpoint: 'https://fhir.example.com/revoke',
            scopesSupported: ['patient/*.read', 'launch'],
            responseTypesSupported: ['code', 'token'],
            grantTypesSupported: ['authorization_code', 'client_credentials'],
            capabilities: ['launch-ehr', 'context-standalone-patient'],
            codeChallengeMethodsSupported: ['S256', 'plain'],
        );

        $array = $config->toArray();

        self::assertSame('https://fhir.example.com/register', $array['registration_endpoint']);
        self::assertSame('https://fhir.example.com/manage', $array['management_endpoint']);
        self::assertSame('https://fhir.example.com/introspect', $array['introspection_endpoint']);
        self::assertSame('https://fhir.example.com/revoke', $array['revocation_endpoint']);
        self::assertSame(['patient/*.read', 'launch'], $array['scopes_supported']);
        self::assertSame(['code', 'token'], $array['response_types_supported']);
        self::assertSame(['authorization_code', 'client_credentials'], $array['grant_types_supported']);
        self::assertSame(['launch-ehr', 'context-standalone-patient'], $array['capabilities']);
        self::assertSame(['S256', 'plain'], $array['code_challenge_methods_supported']);
    }

    #[Test]
    public function toArrayOmitsNullOptionalEndpoints(): void
    {
        $config = new SmartConfiguration(
            issuer: 'https://fhir.example.com',
            authorizationEndpoint: 'https://fhir.example.com/auth',
            tokenEndpoint: 'https://fhir.example.com/token',
            registrationEndpoint: 'https://fhir.example.com/register',
        );

        $array = $config->toArray();

        self::assertArrayHasKey('registration_endpoint', $array);
        self::assertArrayNotHasKey('management_endpoint', $array);
        self::assertArrayNotHasKey('introspection_endpoint', $array);
        self::assertArrayNotHasKey('revocation_endpoint', $array);
    }

    #[Test]
    public function toArrayOmitsScopesSupportedWhenEmpty(): void
    {
        $config = new SmartConfiguration(
            issuer: 'https://fhir.example.com',
            authorizationEndpoint: 'https://fhir.example.com/auth',
            tokenEndpoint: 'https://fhir.example.com/token',
            scopesSupported: [],
        );

        $array = $config->toArray();

        self::assertArrayNotHasKey('scopes_supported', $array);
    }
}
