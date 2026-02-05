<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Security\AllowlistChecker;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

use function base64_encode;

#[CoversClass(StudioAccessGate::class)]
#[CoversClass(AllowlistChecker::class)]
final class StudioAccessGateTest extends TestCase
{
    #[Test]
    public function localModeAlwaysAllowsAccess(): void
    {
        $config = new StudioSecurityConfig();
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);
        $request = $this->createRequest();

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function localModeIgnoresAllSecurityChecks(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['10.0.0.0/8'],
            productionConfirm: false,
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);
        $request = $this->createRequest(remoteAddr: '192.168.1.100');

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function productionModeRequiresProductionConfirm(): void
    {
        $config = new StudioSecurityConfig(productionConfirm: false);
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $request = $this->createRequest();

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Production mode requires STUDIO_PRODUCTION_CONFIRM=true', $result['reason']);
    }

    #[Test]
    public function productionModeWithConfirmPassesFirstCheck(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: [],
            productionConfirm: true,
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $request = $this->createRequest();

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function stagingModeChecksIpAllowlist(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest(remoteAddr: '192.168.1.100');

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('IP not in allowlist', $result['reason']);
    }

    #[Test]
    public function stagingModeAllowsIpInAllowlist(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest(remoteAddr: '10.5.3.1');

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function productionModeChecksIpAllowlistAfterConfirm(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
            productionConfirm: true,
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $request = $this->createRequest(remoteAddr: '192.168.1.100');

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('IP not in allowlist', $result['reason']);
    }

    #[Test]
    public function authRequiredDeniesWithoutAuthHeader(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest();

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function authRequiredDeniesWithInvalidAuthHeader(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest(authHeader: 'Bearer token123');

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function authRequiredDeniesWithWrongCredentials(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $credentials = base64_encode('admin:wrongpassword');
        $request = $this->createRequest(authHeader: "Basic {$credentials}");

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function authRequiredAllowsValidCredentials(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(authHeader: "Basic {$credentials}");

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function authRequiredDeniesWhenUsernameIsNull(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: null,
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(authHeader: "Basic {$credentials}");

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function authRequiredDeniesWhenPasswordIsNull(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: null,
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(authHeader: "Basic {$credentials}");

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function authRequiredDeniesInvalidBase64(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest(authHeader: 'Basic !!!invalid!!!');

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function authRequiredDeniesDecodedStringWithoutColon(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $credentials = base64_encode('nocredentialsseparator');
        $request = $this->createRequest(authHeader: "Basic {$credentials}");

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function emptyAllowlistAllowsAllIps(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest(remoteAddr: '192.168.1.100');

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function productionModeWithAllChecksPassingAllowsAccess(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['10.0.0.0/8'],
            productionConfirm: true,
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(remoteAddr: '10.5.3.1', authHeader: "Basic {$credentials}");

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function ipCheckSkippedWhenRemoteAddrNotString(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $request = $this->createRequest(remoteAddr: null);

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
    }

    private function createRequest(
        ?string $remoteAddr = '127.0.0.1',
        ?string $authHeader = null,
    ): Request {
        $headers = new HeaderBag($authHeader !== null ? ['Authorization' => $authHeader] : []);

        $server = [];
        if ($remoteAddr !== null) {
            $server['REMOTE_ADDR'] = $remoteAddr;
        }

        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: $headers,
            body: '',
            server: $server,
        );
    }
}
