<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivateAccessTokenVerifier;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivateTokenBypassProvider;

#[CoversClass(PrivateTokenBypassProvider::class)]
#[RequiresPhpExtension('gmp')]
final class PrivateTokenBypassProviderTest extends TestCase
{
    #[Test]
    public function bypassesWhenAValidTokenIsPresented(): void
    {
        $provider = $this->provider();

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Authorization' => 'PrivateToken token="' . Rfc9578TestVector::tokenBase64Url() . '"'],
        );

        self::assertTrue($provider->shouldBypass($request));
    }

    #[Test]
    public function doesNotBypassWithoutAToken(): void
    {
        self::assertFalse($this->provider()->shouldBypass(new ServerRequest(method: 'GET', uri: '/')));
    }

    #[Test]
    public function doesNotBypassWithAnInvalidToken(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Authorization' => 'PrivateToken token="bm90LWEtdmFsaWQtdG9rZW4"'],
        );

        self::assertFalse($this->provider()->shouldBypass($request));
    }

    private function provider(): PrivateTokenBypassProvider
    {
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(Rfc9578TestVector::spkiBase64Url());

        return new PrivateTokenBypassProvider($verifier, Rfc9578TestVector::challenge());
    }
}
