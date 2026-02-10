<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Security\AllowlistChecker;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Http\Message\ServerRequest;

use function base64_encode;

#[CoversClass(StudioAccessGate::class)]
#[CoversClass(AllowlistChecker::class)]
final class StudioAccessGateTest extends TestCase
{
    private function makeRequest(
        ?string $remoteAddr = null,
        ?string $authorization = null,
    ): ServerRequest {
        $headers = [];
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }

        $serverParams = [];
        if ($remoteAddr !== null) {
            $serverParams['REMOTE_ADDR'] = $remoteAddr;
        }

        return new ServerRequest(
            method: 'GET',
            uri: '/_studio/dashboard',
            headers: $headers,
            serverParams: $serverParams,
        );
    }

    #[Test]
    public function localModeAlwaysAllowed(): void
    {
        $config = new StudioSecurityConfig();
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);

        $result = $gate->check($this->makeRequest());

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function localModeAllowedEvenWithoutAuth(): void
    {
        $config = new StudioSecurityConfig(authRequired: true, username: 'admin', password: 'pass');
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);

        $result = $gate->check($this->makeRequest());

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function productionModeRequiresProductionConfirm(): void
    {
        $config = new StudioSecurityConfig(productionConfirm: false);
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);

        $result = $gate->check($this->makeRequest(remoteAddr: '127.0.0.1'));

        self::assertFalse($result['allowed']);
        self::assertIsString($result['reason']);
        self::assertStringContainsString('STUDIO_PRODUCTION_CONFIRM', $result['reason']);
    }

    #[Test]
    public function productionModeAllowedWithConfirm(): void
    {
        $config = new StudioSecurityConfig(
            productionConfirm: true,
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);

        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1'));

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function stagingModeBlocksDisallowedIp(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '192.168.1.1'));

        self::assertFalse($result['allowed']);
        self::assertSame('IP not in allowlist', $result['reason']);
    }

    #[Test]
    public function stagingModeAllowsAllowedIp(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.5'));

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function stagingModeRequiresAuthWhenConfigured(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1'));

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function stagingModeAllowsWithValidBasicAuth(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $authHeader = 'Basic ' . base64_encode('admin:secret');
        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1', authorization: $authHeader));

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function basicAuthRejectsWrongCredentials(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $authHeader = 'Basic ' . base64_encode('admin:wrong');
        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1', authorization: $authHeader));

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function basicAuthRejectsNonBasicScheme(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1', authorization: 'Bearer token123'));

        self::assertFalse($result['allowed']);
    }

    #[Test]
    public function basicAuthRejectsInvalidBase64(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1', authorization: 'Basic !!!invalid!!!'));

        self::assertFalse($result['allowed']);
    }

    #[Test]
    public function basicAuthRejectsDecodedStringWithoutColon(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $authHeader = 'Basic ' . base64_encode('no-colon-here');
        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1', authorization: $authHeader));

        self::assertFalse($result['allowed']);
    }

    #[Test]
    public function basicAuthRejectsWhenNoUsernameConfigured(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: null,
            password: null,
            allowedCidrs: ['0.0.0.0/0'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $authHeader = 'Basic ' . base64_encode('admin:secret');
        $result = $gate->check($this->makeRequest(remoteAddr: '10.0.0.1', authorization: $authHeader));

        self::assertFalse($result['allowed']);
    }

    #[Test]
    public function allowlistCheckerHandlesIpv6(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['::1/128'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '::1'));

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function allowlistCheckerHandlesExactIpMatch(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['192.168.1.100'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $allowed = $gate->check($this->makeRequest(remoteAddr: '192.168.1.100'));
        self::assertTrue($allowed['allowed']);

        $blocked = $gate->check($this->makeRequest(remoteAddr: '192.168.1.101'));
        self::assertFalse($blocked['allowed']);
    }

    #[Test]
    public function allowlistCheckerAllowsAllWhenEmpty(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '1.2.3.4'));

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function defaultCidrsAllowLocalhostIpv4(): void
    {
        $config = new StudioSecurityConfig();
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);

        $result = $gate->check($this->makeRequest(remoteAddr: '127.0.0.1'));

        self::assertTrue($result['allowed']);
    }
}
