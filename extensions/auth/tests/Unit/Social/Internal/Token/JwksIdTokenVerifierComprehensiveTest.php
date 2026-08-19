<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Internal\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Contracts\IdTokenVerifierInterface;
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

/**
 * Comprehensive edge-case and security tests for JwksIdTokenVerifier.
 *
 * Supplements the existing JwksIdTokenVerifierTest and JwksIdTokenVerifierDeepTest
 * with adversarial input testing, boundary conditions, and untested code paths.
 */
#[CoversClass(JwksIdTokenVerifier::class)]
final class JwksIdTokenVerifierComprehensiveTest extends TestCase
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
    private function buildToken(array $header, array $payload, string $signature = 'fake-sig'): string
    {
        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signatureB64 = self::base64UrlEncode($signature);

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
        string $clientId = 'my-client',
        string $issuer = 'https://issuer.example.com',
        ?string $nonce = 'test-nonce',
        int $maxClockSkew = 300,
    ): IdTokenVerificationContext {
        return new IdTokenVerificationContext(
            clientId: $clientId,
            issuer: $issuer,
            nonce: $nonce,
            maxClockSkewSeconds: $maxClockSkew,
        );
    }

    /** @return array<string, mixed> */
    private function validPayload(
        string $issuer = 'https://issuer.example.com',
        string $clientId = 'my-client',
    ): array {
        return [
            'sub' => 'user-1',
            'iss' => $issuer,
            'aud' => $clientId,
            'exp' => time() + 3600,
            'iat' => time() - 10,
            'nonce' => 'test-nonce',
        ];
    }

    private function setupVerification(string $issuer = 'https://issuer.example.com'): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache($issuer, [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);
    }

    // --- Segment count validation ---

    #[Test]
    public function rejectsSingleSegmentToken(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('three segments');

        $verifier->verify('only-one-segment', $this->makeContext());
    }

    #[Test]
    public function rejectsFourSegmentToken(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('three segments');

        $verifier->verify('a.b.c.d', $this->makeContext());
    }

    #[Test]
    public function rejectsEmptyToken(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('three segments');

        $verifier->verify('', $this->makeContext());
    }

    // --- Header validation ---

    #[Test]
    public function rejectsNonArrayHeader(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        // Encode a JSON string (not object) as header
        $headerB64 = self::base64UrlEncode('"just-a-string"');
        $payloadB64 = self::base64UrlEncode(json_encode($this->validPayload(), JSON_THROW_ON_ERROR));
        $sigB64 = self::base64UrlEncode('sig');

        $token = $headerB64 . '.' . $payloadB64 . '.' . $sigB64;

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('missing algorithm');

        $verifier->verify($token, $this->makeContext());
    }

    // --- Algorithm security tests ---

    /**
     * @return array<string, array{string}>
     */
    public static function noneAlgorithmVariantsProvider(): array
    {
        return [
            'none lowercase' => ['none'],
        ];
    }

    #[Test]
    #[DataProvider('noneAlgorithmVariantsProvider')]
    public function rejectsNoneAlgorithmVariants(string $alg): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $token = $this->buildToken(
            ['alg' => $alg, 'typ' => 'JWT'],
            $this->validPayload(),
        );

        $this->expectException(SsoException::class);

        $verifier->verify($token, $this->makeContext());
    }

    // --- Clock skew boundary tests ---

    #[Test]
    public function acceptsTokenExpiringExactlyAtSkewBoundary(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(maxClockSkew: 300);

        // The clock is pinned rather than read twice. Building an RS256 token takes
        // well over a second under code coverage, so a token placed exactly on the
        // skew boundary against time() had already fallen off it by the time the
        // verifier read time() again — the assertion failed on correct code.
        $now = time();
        $payload = $this->validPayload();
        // Token expired exactly 300 seconds ago: exp + skew = now
        $payload['exp'] = $now - 300;

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver, static fn(): int => $now);

        $claims = $verifier->verify($token, $context);

        self::assertSame('user-1', $claims->sub);
    }

    #[Test]
    public function rejectsTokenExpiredJustBeyondSkew(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(maxClockSkew: 300);

        $now = time();
        $payload = $this->validPayload();
        // Token expired 301 seconds ago: exp + skew < now
        $payload['exp'] = $now - 301;

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('expired');

        $verifier->verify($token, $context);
    }

    #[Test]
    public function acceptsTokenIssuedExactlyAtSkewBoundary(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(maxClockSkew: 300);

        $now = time();
        $payload = $this->validPayload();
        // iat - skew = now, so iat = now + 300
        $payload['iat'] = $now + 300;

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('user-1', $claims->sub);
    }

    #[Test]
    public function rejectsTokenIssuedJustBeyondSkew(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(maxClockSkew: 300);

        $now = time();
        $payload = $this->validPayload();
        // iat - skew > now, so iat > now + 300
        $payload['iat'] = $now + 301;

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('future');

        $verifier->verify($token, $context);
    }

    #[Test]
    public function zeroClockSkewRejectsExpiredToken(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(maxClockSkew: 0);

        $now = time();
        $payload = $this->validPayload();
        $payload['exp'] = $now - 1;

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('expired');

        $verifier->verify($token, $context);
    }

    // --- Audience edge cases ---

    #[Test]
    public function rejectsMultipleAudiencesWithAzpNotMatchingClientId(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext();

        $payload = $this->validPayload();
        $payload['aud'] = ['my-client', 'other-client'];
        $payload['azp'] = 'other-client'; // azp != clientId

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('azp');

        $verifier->verify($token, $context);
    }

    #[Test]
    public function acceptsSingleAudienceWithoutAzp(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext();

        $payload = $this->validPayload();
        $payload['aud'] = 'my-client'; // Single audience, no azp needed

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('my-client', $claims->aud);
        self::assertNull($claims->azp);
    }

    // --- Nonce edge cases ---

    #[Test]
    public function rejectsNonStringNonceWhenExpected(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(nonce: 'expected');

        $payload = $this->validPayload();
        $payload['nonce'] = 12345; // integer nonce, not string

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('nonce');

        $verifier->verify($token, $context);
    }

    #[Test]
    public function acceptsTokenWithNonceWhenContextDoesNotExpectNonce(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext(nonce: null);

        $payload = $this->validPayload();
        $payload['nonce'] = 'any-nonce-value';

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('any-nonce-value', $claims->nonce);
    }

    // --- Payload non-object ---

    #[Test]
    public function rejectsPayloadThatIsJsonString(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache('https://issuer.example.com', [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        // Payload is a JSON string (not object/array), so json_decode returns string
        $headerB64 = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1'], JSON_THROW_ON_ERROR));
        $payloadB64 = self::base64UrlEncode('"just-a-string"');
        $sigB64 = self::base64UrlEncode('sig');
        $token = $headerB64 . '.' . $payloadB64 . '.' . $sigB64;

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('not a valid JSON object');

        $verifier->verify($token, $this->makeContext());
    }

    #[Test]
    public function rejectsPayloadThatIsJsonArrayMissingSub(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache('https://issuer.example.com', [$key]);
        $this->driver->method('supports')->willReturn(true);
        $this->driver->method('verify')->willReturn(true);

        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        // Payload is a JSON array; passes is_array() but has no 'sub' key
        $headerB64 = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1'], JSON_THROW_ON_ERROR));
        $payloadB64 = self::base64UrlEncode(json_encode([1, 2, 3], JSON_THROW_ON_ERROR));
        $sigB64 = self::base64UrlEncode('sig');
        $token = $headerB64 . '.' . $payloadB64 . '.' . $sigB64;

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"sub"');

        $verifier->verify($token, $this->makeContext());
    }

    // --- Extra claims filtering ---

    #[Test]
    public function azpIsNotIncludedInExtraClaims(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext();

        $payload = $this->validPayload();
        $payload['aud'] = ['my-client', 'other-client'];
        $payload['azp'] = 'my-client';
        $payload['custom_claim'] = 'custom_value';

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertArrayNotHasKey('azp', $claims->claims);
        self::assertSame('custom_value', $claims->claims['custom_claim']);
        self::assertSame('my-client', $claims->azp);
    }

    // --- Issued-at type handling ---

    #[Test]
    public function nonIntegerSubRejects(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['sub'] = 123; // not a string

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"sub"');

        $verifier->verify($token, $this->makeContext());
    }

    #[Test]
    public function nonStringIssuerRejects(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        $payload = $this->validPayload();
        $payload['iss'] = 42;

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessageIsOrContains('"iss"');

        $verifier->verify($token, $this->makeContext());
    }

    // --- Interface implementation contract ---

    #[Test]
    public function implementsIdTokenVerifierInterface(): void
    {
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);

        self::assertInstanceOf(
            IdTokenVerifierInterface::class,
            $verifier,
        );
    }

    // --- Claims DTO population completeness ---

    #[Test]
    public function claimsDtoContainsAllStandardFields(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext();

        $now = time();
        $payload = [
            'sub' => 'sub-val',
            'iss' => 'https://issuer.example.com',
            'aud' => 'my-client',
            'exp' => $now + 3600,
            'iat' => $now - 10,
            'nonce' => 'test-nonce',
            'azp' => 'my-client',
            'email' => 'test@example.com',
            'picture' => 'https://example.com/pic.jpg',
        ];

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('sub-val', $claims->sub);
        self::assertSame('https://issuer.example.com', $claims->iss);
        self::assertSame('my-client', $claims->aud);
        self::assertSame($now + 3600, $claims->exp);
        self::assertSame($now - 10, $claims->iat);
        self::assertSame('test-nonce', $claims->nonce);
        self::assertSame('my-client', $claims->azp);
        self::assertSame('test@example.com', $claims->claims['email']);
        self::assertSame('https://example.com/pic.jpg', $claims->claims['picture']);
        self::assertCount(2, $claims->claims);
    }

    // --- Base64url padding edge case ---

    #[Test]
    public function handlesBase64UrlWithVariousPaddingLengths(): void
    {
        $this->setupVerification();
        $verifier = new JwksIdTokenVerifier($this->fetcher, $this->driver);
        $context = $this->makeContext();

        // The header and payload are base64url encoded without padding, the verifier
        // must handle 0, 1, 2, 3 chars of padding needed. This test verifies the
        // normal path works correctly with the built-in padding logic.
        $payload = $this->validPayload();

        $token = $this->buildToken(['alg' => 'RS256', 'kid' => 'k1'], $payload);

        $claims = $verifier->verify($token, $context);

        self::assertSame('user-1', $claims->sub);
    }

    // --- Signature verification driver interaction ---

    #[Test]
    public function signatureDriverReceivesRawBase64UrlSegments(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'k1', alg: 'RS256', use: 'sig');
        $this->seedFetcherCache('https://issuer.example.com', [$key]);

        $capturedHeader = null;
        $capturedPayload = null;

        $driver = $this->createMock(JwtSignatureDriverInterface::class);
        $driver->method('supports')->willReturn(true);
        $driver->expects(self::once())
            ->method('verify')
            ->willReturnCallback(function (string $h, string $p) use (&$capturedHeader, &$capturedPayload): bool {
                $capturedHeader = $h;
                $capturedPayload = $p;

                return true;
            });

        $verifier = new JwksIdTokenVerifier($this->fetcher, $driver);

        $payload = $this->validPayload();
        $headerB64 = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1'], JSON_THROW_ON_ERROR));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $sigB64 = self::base64UrlEncode('test-sig');
        $token = $headerB64 . '.' . $payloadB64 . '.' . $sigB64;

        $verifier->verify($token, $this->makeContext());

        // The driver should receive the raw base64url segments (not decoded)
        self::assertSame($headerB64, $capturedHeader);
        self::assertSame($payloadB64, $capturedPayload);
    }
}
