<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\Auth\OAuth2\Oidc\IdTokenBuilder;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwtSignerInterface;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcConfig;

final class IdTokenBuilderTest extends TestCase
{
    #[Test]
    public function buildPassesCorrectClaimsToSigner(): void
    {
        $config = OidcConfig::fromArray([
            'issuer' => 'https://auth.example.com',
            'signing_key_id' => 'key-1',
        ]);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getSubjectIdentifier')->willReturn('sub-user-1');
        $claimsProvider->method('getClaims')->willReturn(['name' => 'Test User']);

        $signer = $this->createMock(JwtSignerInterface::class);
        $signer->expects($this->once())
            ->method('sign')
            ->with(
                $this->callback(function (array $claims): bool {
                    return $claims['iss'] === 'https://auth.example.com'
                        && $claims['sub'] === 'sub-user-1'
                        && $claims['aud'] === 'client-abc'
                        && isset($claims['exp'], $claims['iat'])
                        && $claims['nonce'] === 'nonce-xyz'
                        && $claims['name'] === 'Test User';
                }),
                'key-1',
            )
            ->willReturn('signed.jwt.token');

        $builder = new IdTokenBuilder($config, $claimsProvider, $signer);
        $token = $builder->build('user-1', 'client-abc', ['openid', 'profile'], 'nonce-xyz');

        self::assertSame('signed.jwt.token', $token);
    }

    #[Test]
    public function buildOmitsNonceWhenNull(): void
    {
        $config = OidcConfig::fromArray([
            'issuer' => 'https://auth.example.com',
            'signing_key_id' => 'key-1',
        ]);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getSubjectIdentifier')->willReturn('sub-1');
        $claimsProvider->method('getClaims')->willReturn([]);

        $signer = $this->createMock(JwtSignerInterface::class);
        $signer->expects($this->once())
            ->method('sign')
            ->with(
                $this->callback(fn(array $claims): bool => !isset($claims['nonce'])),
                $this->anything(),
            )
            ->willReturn('jwt.without.nonce');

        $builder = new IdTokenBuilder($config, $claimsProvider, $signer);
        $token = $builder->build('user-1', 'client-1', ['openid'], null);

        self::assertSame('jwt.without.nonce', $token);
    }

    #[Test]
    public function buildUsesCustomTtl(): void
    {
        $config = OidcConfig::fromArray([
            'issuer' => 'https://auth.example.com',
            'signing_key_id' => 'key-1',
        ]);

        $claimsProvider = $this->createStub(UserClaimsProviderInterface::class);
        $claimsProvider->method('getSubjectIdentifier')->willReturn('sub-1');
        $claimsProvider->method('getClaims')->willReturn([]);

        $signer = $this->createMock(JwtSignerInterface::class);
        $signer->expects($this->once())
            ->method('sign')
            ->with(
                $this->callback(function (array $claims): bool {
                    $diff = $claims['exp'] - $claims['iat'];
                    return $diff === 3600;
                }),
                $this->anything(),
            )
            ->willReturn('jwt');

        $builder = new IdTokenBuilder($config, $claimsProvider, $signer);
        $builder->build('user-1', 'client-1', ['openid'], null, 3600);
    }
}
