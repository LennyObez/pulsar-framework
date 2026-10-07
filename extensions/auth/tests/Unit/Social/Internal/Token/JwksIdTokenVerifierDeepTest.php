<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Internal\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Contracts\JwtSignatureDriverInterface;
use Pulsar\Extension\Auth\Social\Domain\IdTokenVerificationContext;
use Pulsar\Extension\Auth\Social\Domain\JwkKey;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use Pulsar\Extension\Auth\Social\Internal\Token\JwksFetcher;
use Pulsar\Extension\Auth\Social\Internal\Token\JwksIdTokenVerifier;
use ReflectionProperty;

use function base64_encode;
use function json_encode;
use function str_replace;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(JwksIdTokenVerifier::class)]
final class JwksIdTokenVerifierDeepTest extends TestCase
{
    private JwksFetcher $fetcher;
    private JwtSignatureDriverInterface&Stub $driver;

    protected function setUp(): void
    {
        $this->fetcher = new JwksFetcher();
        $this->driver = $this->createStub(JwtSignatureDriverInterface::class);
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function buildToken(array $header, array $payload): string
    {
        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signatureB64 = self::base64UrlEncode('sig');

        return $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;
    }

    /**
     * @param list<JwkKey> $keys
     */
    private function seedFetcherCache(string $issuer, array $keys): void
    {
        $jwksUri = $issuer . '/.well-known/jwks.json';
        $cache = new ReflectionProperty(JwksFetcher::class, 'cache');
        $cache->setValue($this->fetcher, [$jwksUri => $keys]);
    }

    private function makeContext(
        ?string $nonce = 'test-nonce',
        string $issuer = 'https://issuer.example.com',
    ): IdTokenVerificationContext {
        return new IdTokenVerificationContext(
            clientId: 'my-client',
            issuer: $issuer,
            nonce: $nonce,
            maxClockSkewSeconds: 300,
        );
    }

    /** @return array<string, mixed> */
    private function validPayload(string $issuer = 'https://issuer.example.com'): array
    {
        return [
            'sub' => 'user-1',
            'iss' => $issuer,
            'aud' => 'my-client',
            'exp' => time() + 3600,
            'iat' => time() - 10,
            'nonce' => 'test-nonce',
        ];
    }

    #[Test]
    public function rejectsMissingAlgInHeader(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(['typ' => 'JWT'], $this->validPayload());

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('missing algorithm');
        $verifier->verify($token, $this->makeContext());
    }

    #[Test]
    public function rejectsMissingIss(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['iss']);

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"iss"');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function rejectsMissingAud(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['aud']);

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"aud"');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function rejectsMissingExp(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['exp']);

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"exp"');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function rejectsMissingIat(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['iat']);

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"iat"');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function acceptsTokenWithNullNonceContext(): void
    {
        $context = $this->makeContext(nonce: null);
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['nonce']);

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('user-1', $claims->sub);
        self::assertNull($claims->nonce);
    }

    #[Test]
    public function rejectsMissingNonceWhenExpected(): void
    {
        $context = $this->makeContext(nonce: 'expected-nonce');
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['nonce']);

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('nonce');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function keyNotFoundInJwksThrowsException(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'other-kid', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'missing-kid'],
            $this->validPayload(),
        );

        // The fetcher will clear cache and try HTTP re-fetch, which fails with jwksFetchFailed
        $this->expectException(SsoException::class);
        $verifier->verify($token, $context);
    }

    #[Test]
    public function emptyJwksFailsWhenNoKidInHeader(): void
    {
        $context = $this->makeContext();
        $this->seedFetcherCache($context->issuer, []);
        $this->driver->method('supports')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'RS256'],
            $this->validPayload(),
        );

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('no keys');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function multipleAudiencesWithWrongAzpRejected(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['aud'] = ['my-client', 'other-client'];
        $payload['azp'] = 'wrong-azp';

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('azp');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function nonIntExpDefaultsToZero(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['exp'] = 'not-int';

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        // exp defaults to 0, which is expired
        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('expired');
        $verifier->verify($token, $context);
    }

    #[Test]
    public function extraClaimsExcludeStandardKeys(): void
    {
        $context = $this->makeContext();
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($context->issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['email'] = 'test@example.com';
        $payload['name'] = 'Test User';

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('test@example.com', $claims->claims['email']);
        self::assertSame('Test User', $claims->claims['name']);
        self::assertArrayNotHasKey('sub', $claims->claims);
        self::assertArrayNotHasKey('iss', $claims->claims);
        self::assertArrayNotHasKey('aud', $claims->claims);
        self::assertArrayNotHasKey('exp', $claims->claims);
        self::assertArrayNotHasKey('iat', $claims->claims);
        self::assertArrayNotHasKey('nonce', $claims->claims);
    }
}
