<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Security\StudioAccessGate;

final class StudioAccessGateTest extends TestCase
{
    #[Test]
    public function local_mode_always_allows(): void
    {
        $gate = $this->createGate(EnvironmentMode::Local);
        $request = $this->createRequest();

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    #[Test]
    public function production_without_confirm_denies(): void
    {
        $gate = $this->createGate(EnvironmentMode::Production, productionConfirm: false);
        $request = $this->createRequest();

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertStringContainsString('STUDIO_PRODUCTION_CONFIRM', $result['reason']);
    }

    #[Test]
    public function staging_with_valid_auth_allows(): void
    {
        $gate = $this->createGate(
            EnvironmentMode::Staging,
            authRequired: true,
            username: 'admin',
            password: 'secret',
        );

        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(
            authorization: 'Basic ' . $credentials,
            remoteAddr: '127.0.0.1',
        );

        $result = $gate->check($request);

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function staging_without_auth_denies(): void
    {
        $gate = $this->createGate(
            EnvironmentMode::Staging,
            authRequired: true,
            username: 'admin',
            password: 'secret',
        );

        $request = $this->createRequest(remoteAddr: '127.0.0.1');

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('Authentication required', $result['reason']);
    }

    #[Test]
    public function staging_with_wrong_credentials_denies(): void
    {
        $gate = $this->createGate(
            EnvironmentMode::Staging,
            authRequired: true,
            username: 'admin',
            password: 'secret',
        );

        $credentials = base64_encode('admin:wrong');
        $request = $this->createRequest(
            authorization: 'Basic ' . $credentials,
            remoteAddr: '127.0.0.1',
        );

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
    }

    #[Test]
    public function ip_not_in_allowlist_denies(): void
    {
        $gate = $this->createGate(
            EnvironmentMode::Staging,
            authRequired: false,
            allowedCidrs: ['10.0.0.0/8'],
        );

        $request = $this->createRequest(remoteAddr: '192.168.1.1');

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
        self::assertSame('IP not in allowlist', $result['reason']);
    }

    #[Test]
    public function non_basic_auth_scheme_denies(): void
    {
        $gate = $this->createGate(
            EnvironmentMode::Staging,
            authRequired: true,
            username: 'admin',
            password: 'secret',
        );

        $request = $this->createRequest(
            authorization: 'Bearer token123',
            remoteAddr: '127.0.0.1',
        );

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
    }

    #[Test]
    public function null_credentials_config_denies(): void
    {
        $gate = $this->createGate(
            EnvironmentMode::Staging,
            authRequired: true,
            username: null,
            password: null,
        );

        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(
            authorization: 'Basic ' . $credentials,
            remoteAddr: '127.0.0.1',
        );

        $result = $gate->check($request);

        self::assertFalse($result['allowed']);
    }

    /**
     * @param list<string> $allowedCidrs
     */
    private function createGate(
        EnvironmentMode $mode,
        bool $productionConfirm = true,
        bool $authRequired = false,
        ?string $username = null,
        ?string $password = null,
        array $allowedCidrs = ['0.0.0.0/0'],
    ): StudioAccessGate {
        $config = StudioSecurityConfig::fromArray([
            'production_confirm' => $productionConfirm,
            'auth_required' => $authRequired,
            'username' => $username,
            'password' => $password,
            'allowed_cidrs' => $allowedCidrs,
        ], Environment::load());

        return new StudioAccessGate($config, $mode);
    }

    private function createRequest(
        string $authorization = '',
        string $remoteAddr = '127.0.0.1',
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match (strtolower($name)) {
                'authorization' => $authorization,
                default => '',
            },
        );
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);

        return $request;
    }
}
