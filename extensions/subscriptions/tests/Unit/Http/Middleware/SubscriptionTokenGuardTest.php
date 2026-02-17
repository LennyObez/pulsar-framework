<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Subscriptions\Http\Middleware\SubscriptionTokenGuard;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

use function base64_encode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function rtrim;
use function str_replace;
use function time;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

#[CoversClass(SubscriptionTokenGuard::class)]
final class SubscriptionTokenGuardTest extends TestCase
{
    private string $publicKey;
    private string $privateKey;

    protected function setUp(): void
    {
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($keyPair);

        openssl_pkey_export($keyPair, $privateKeyPem);
        $this->privateKey = $privateKeyPem;

        $details = openssl_pkey_get_details($keyPair);
        self::assertIsArray($details);
        $this->publicKey = $details['key'];
    }

    #[Test]
    public function rejectsMissingAuthorizationHeader(): void
    {
        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(method: 'GET', uri: '/api/subscriptions');
        $handler = $this->okHandler();

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Missing', (string) $response->getBody());
    }

    #[Test]
    public function rejectsMalformedAuthorizationHeader(): void
    {
        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Basic dXNlcjpwYXNz'],
        );
        $handler = $this->okHandler();

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidJwtStructure(): void
    {
        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer not.a.valid.jwt.token'],
        );
        $handler = $this->okHandler();

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsExpiredToken(): void
    {
        $token = $this->createJwt(['sub' => 'user-1', 'exp' => time() - 300]);
        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsTokenWithFutureNbf(): void
    {
        $token = $this->createJwt([
            'sub' => 'user-1',
            'exp' => time() + 3600,
            'nbf' => time() + 600,
        ]);
        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsTokenWithWrongIssuer(): void
    {
        $token = $this->createJwt([
            'sub' => 'user-1',
            'exp' => time() + 3600,
            'iss' => 'wrong-issuer',
        ]);
        $guard = new SubscriptionTokenGuard($this->publicKey, expectedIssuer: 'expected-issuer');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsTokenWithWrongAudience(): void
    {
        $token = $this->createJwt([
            'sub' => 'user-1',
            'exp' => time() + 3600,
            'aud' => 'wrong-audience',
        ]);
        $guard = new SubscriptionTokenGuard($this->publicKey, expectedAudience: 'expected-audience');
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsTokenWithMissingSubject(): void
    {
        $token = $this->createJwt(['exp' => time() + 3600]);
        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsTokenSignedWithDifferentKey(): void
    {
        // Create a different key pair
        $otherKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($otherKey);
        openssl_pkey_export($otherKey, $otherPrivateKey);

        $token = $this->createJwt(
            ['sub' => 'user-1', 'exp' => time() + 3600],
            $otherPrivateKey,
        );

        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsNonRS256Algorithm(): void
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode(['sub' => 'user-1', 'exp' => time() + 3600], JSON_THROW_ON_ERROR));
        $token = $header . '.' . $payload . '.' . self::base64UrlEncode('fake-sig');

        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $response = $guard->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function acceptsValidTokenAndSetsUserId(): void
    {
        $token = $this->createJwt([
            'sub' => 'user-42',
            'exp' => time() + 3600,
        ]);

        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $capturedUserId = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(function ($req) use (&$capturedUserId) {
                $capturedUserId = $req->getAttribute('user_id');

                return Response::text('OK');
            });

        $response = $guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $capturedUserId);
    }

    #[Test]
    public function acceptsValidTokenWithCorrectIssuerAndAudience(): void
    {
        $token = $this->createJwt([
            'sub' => 'user-99',
            'exp' => time() + 3600,
            'iss' => 'pulsar-auth',
            'aud' => 'subscriptions-api',
        ]);

        $guard = new SubscriptionTokenGuard(
            $this->publicKey,
            expectedIssuer: 'pulsar-auth',
            expectedAudience: 'subscriptions-api',
        );
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function respectsClockSkewForExpiration(): void
    {
        // Token expired 20 seconds ago, but clock skew allows 30 seconds
        $token = $this->createJwt([
            'sub' => 'user-1',
            'exp' => time() - 20,
        ]);

        $guard = new SubscriptionTokenGuard($this->publicKey);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/subscriptions',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function createJwt(array $claims, ?string $privateKey = null): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

        $data = $header . '.' . $payload;

        openssl_sign($data, $signature, $privateKey ?? $this->privateKey, OPENSSL_ALGO_SHA256);
        self::assertNotEmpty($signature);

        return $data . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($data)), '=');
    }

    private function okHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }
}
