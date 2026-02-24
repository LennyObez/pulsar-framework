<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\WebAuthn\Contract\WebAuthnServerInterface;

#[CoversClass(WebAuthnServer::class)]
final class WebAuthnServerTest extends TestCase
{
    private WebAuthnServer $server;

    protected function setUp(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'Test RP',
            rpId: 'example.com',
            origin: 'https://example.com',
        );

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $attestationVerifier = $this->createStub(AttestationVerifierInterface::class);
        $credentialRepository = $this->createStub(CredentialRepositoryInterface::class);

        $registrationCeremony = new RegistrationCeremony(
            $config,
            $attestationVerifier,
            $credentialRepository,
            $auditLogger,
        );

        $authenticationCeremony = new AuthenticationCeremony(
            $config,
            $credentialRepository,
            $auditLogger,
        );

        $this->server = new WebAuthnServer($registrationCeremony, $authenticationCeremony);
    }

    #[Test]
    public function implementsWebAuthnServerInterface(): void
    {
        self::assertInstanceOf(WebAuthnServerInterface::class, $this->server);
    }

    #[Test]
    public function generateRegistrationOptionsReturnsRegistrationOptions(): void
    {
        $result = $this->server->generateRegistrationOptions(
            'user-550e8400-e29b-41d4-a716-446655440000',
            'jane.doe@example.com',
            [],
        );

        self::assertInstanceOf(RegistrationOptions::class, $result);
        self::assertNotEmpty($result->challenge);
        self::assertArrayHasKey('rp', $result->publicKeyOptions);
        $rp = $result->publicKeyOptions['rp'];
        self::assertIsArray($rp);
        self::assertSame('Test RP', $rp['name']);
        self::assertSame('example.com', $rp['id']);
    }

    #[Test]
    public function generateRegistrationOptionsExcludesCredentials(): void
    {
        $result = $this->server->generateRegistrationOptions(
            'user-550e8400',
            'jane.doe@example.com',
            ['existing-cred-1'],
        );

        self::assertInstanceOf(RegistrationOptions::class, $result);
        $excludeCredentials = $result->publicKeyOptions['excludeCredentials'];
        self::assertIsArray($excludeCredentials);
        self::assertCount(1, $excludeCredentials);
    }

    #[Test]
    public function generateAuthenticationOptionsReturnsAuthenticationOptions(): void
    {
        $result = $this->server->generateAuthenticationOptions(null);

        self::assertInstanceOf(AuthenticationOptions::class, $result);
        self::assertNotEmpty($result->challenge);
        self::assertSame('example.com', $result->publicKeyOptions['rpId']);
    }

    #[Test]
    public function generateAuthenticationOptionsContainsTimeout(): void
    {
        $result = $this->server->generateAuthenticationOptions(null);

        self::assertSame(60000, $result->publicKeyOptions['timeout']);
    }

    #[Test]
    public function generateRegistrationOptionsContainsAuthenticatorSelection(): void
    {
        $result = $this->server->generateRegistrationOptions(
            'user-id',
            'user@example.com',
        );

        self::assertArrayHasKey('authenticatorSelection', $result->publicKeyOptions);
        $authSel = $result->publicKeyOptions['authenticatorSelection'];
        self::assertIsArray($authSel);
        self::assertSame('preferred', $authSel['userVerification']);
    }
}
