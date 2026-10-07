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
final class JwksIdTokenVerifierTest extends TestCase
{
    private JwksFetcher $fetcher;
    private JwtSignatureDriverInterface&Stub $driver;
    private IdTokenVerificationContext $context;

    protected function setUp(): void
    {
        $this->fetcher = new JwksFetcher();
        $this->driver = $this->createStub(JwtSignatureDriverInterface::class);
        $this->context = new IdTokenVerificationContext(
            clientId: 'client-123',
            issuer: 'https://accounts.google.com',
            nonce: 'test-nonce',
            maxClockSkewSeconds: 300,
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function buildToken(array $header, array $payload): string
    {
        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signatureB64 = self::base64UrlEncode('fake-signature');

        return $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'sub' => 'user-456',
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-123',
            'exp' => time() + 3600,
            'iat' => time() - 60,
            'nonce' => 'test-nonce',
            'email' => 'user@example.com',
        ];
    }

    /**
     * Pre-seed the JwksFetcher's internal cache to avoid HTTP calls.
     *
     * @param list<JwkKey> $keys
     */
    private function seedFetcherCache(array $keys): void
    {
        $jwksUri = $this->context->issuer . '/.well-known/jwks.json';
        $cache = new ReflectionProperty(JwksFetcher::class, 'cache');
        $cache->setValue($this->fetcher, [$jwksUri => $keys]);
    }

    private function setupValidVerification(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'key-1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache([$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);
    }

    #[Test]
    public function verifyValidToken(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1', 'typ' => 'JWT'],
            $this->validPayload(),
        );

        $claims = $verifier->verify($token, $this->context);

        self::assertSame('user-456', $claims->sub);
        self::assertSame('https://accounts.google.com', $claims->iss);
        self::assertSame('client-123', $claims->aud);
        self::assertSame('test-nonce', $claims->nonce);
        self::assertSame('user@example.com', $claims->claims['email']);
    }

    #[Test]
    public function rejectsTwoSegmentToken(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $this->expectException(SsoException::class);

        $verifier->verify('header.payload', $this->context);
    }

    #[Test]
    public function rejectsNoneAlgorithm(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'none', 'typ' => 'JWT'],
            $this->validPayload(),
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsUnsupportedAlgorithm(): void
    {
        $this->driver->method('supports')->willReturn(false);
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            $this->validPayload(),
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsInvalidSignature(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'key-1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache([$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(false);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1', 'typ' => 'JWT'],
            $this->validPayload(),
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsExpiredToken(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['exp'] = time() - 600;

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsTokenIssuedInFuture(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['iat'] = time() + 600;

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsIssuerMismatch(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['iss'] = 'https://evil.com';

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsAudienceMismatch(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['aud'] = 'wrong-client';

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsNonceMismatch(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['nonce'] = 'wrong-nonce';

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function rejectsMissingSub(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        unset($payload['sub']);

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function multipleAudiencesRequireAzp(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['aud'] = ['client-123', 'other-client'];
        // No azp claim

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }

    #[Test]
    public function multipleAudiencesWithMatchingAzp(): void
    {
        $this->setupValidVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['aud'] = ['client-123', 'other-client'];
        $payload['azp'] = 'client-123';

        $token = $this->buildToken(
            ['alg' => 'RS256', 'kid' => 'key-1'],
            $payload,
        );

        $claims = $verifier->verify($token, $this->context);

        self::assertSame('client-123', $claims->azp);
    }

    #[Test]
    public function singleKeyJwksUsedWhenNoKidInHeader(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: null, alg: 'RS256', use: 'sig');
        $this->seedFetcherCache([$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'RS256'],
            $this->validPayload(),
        );

        $claims = $verifier->verify($token, $this->context);

        self::assertSame('user-456', $claims->sub);
    }

    #[Test]
    public function multiKeyJwksFailsWhenNoKidInHeader(): void
    {
        $key1 = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $key2 = new JwkKey(kty: 'RSA', kid: 'k2', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache([$key1, $key2]);
        $this->driver->method('supports')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => 'RS256'],
            $this->validPayload(),
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->context);
    }
}
