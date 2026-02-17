<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Smart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Smart\SmartConfiguration;

#[CoversClass(SmartConfiguration::class)]
final class SmartConfigurationTest extends TestCase
{
    public function testMinimalConfiguration(): void
    {
        $config = new SmartConfiguration(
            issuer: 'https://fhir.example.org',
            authorizationEndpoint: 'https://fhir.example.org/oauth/authorize',
            tokenEndpoint: 'https://fhir.example.org/oauth/token',
        );

        $array = $config->toArray();

        self::assertSame('https://fhir.example.org', $array['issuer']);
        self::assertSame('https://fhir.example.org/oauth/authorize', $array['authorization_endpoint']);
        self::assertSame('https://fhir.example.org/oauth/token', $array['token_endpoint']);
        self::assertSame(['code'], $array['response_types_supported']);
        self::assertSame(['S256'], $array['code_challenge_methods_supported']);
        self::assertArrayNotHasKey('registration_endpoint', $array);
    }

    public function testFullConfiguration(): void
    {
        $config = new SmartConfiguration(
            issuer: 'https://fhir.example.org',
            authorizationEndpoint: 'https://fhir.example.org/oauth/authorize',
            tokenEndpoint: 'https://fhir.example.org/oauth/token',
            registrationEndpoint: 'https://fhir.example.org/oauth/register',
            introspectionEndpoint: 'https://fhir.example.org/oauth/introspect',
            revocationEndpoint: 'https://fhir.example.org/oauth/revoke',
            scopesSupported: ['patient/*.read', 'user/*.write', 'launch'],
            capabilities: ['launch-ehr', 'client-public', 'permission-patient'],
        );

        $array = $config->toArray();

        self::assertArrayHasKey('registration_endpoint', $array);
        self::assertArrayHasKey('introspection_endpoint', $array);
        self::assertArrayHasKey('revocation_endpoint', $array);
        self::assertSame(['patient/*.read', 'user/*.write', 'launch'], $array['scopes_supported']);
        self::assertSame(['launch-ehr', 'client-public', 'permission-patient'], $array['capabilities']);
    }
}
