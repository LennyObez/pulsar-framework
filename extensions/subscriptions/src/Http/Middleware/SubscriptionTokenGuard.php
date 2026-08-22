<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use SensitiveParameter;

use function base64_decode;
use function count;
use function explode;
use function hash_equals;
use function is_array;
use function is_string;
use function json_decode;
use function openssl_verify;
use function preg_match;
use function time;

use const OPENSSL_ALGO_SHA256;

/**
 * JWT-based token guard for subscription API authentication.
 *
 * Validates RS256 JWT Bearer tokens against a configured public key.
 * Verifies the token structure, signature, expiration (exp), not-before (nbf),
 * issuer (iss), and audience (aud) claims.
 *
 * On success, sets the `user_id` request attribute to the `sub` claim.
 * On failure, returns a 401 JSON response.
 */
#[Internal(reason: 'HTTP middleware; implementation detail')]
final readonly class SubscriptionTokenGuard implements MiddlewareInterface
{
    private const int CLOCK_SKEW_SECONDS = 30;

    /**
     * @param string      $jwtPublicKey PEM-encoded RSA public key for RS256 verification
     * @param string|null $expectedIssuer Expected `iss` claim value (null to skip check)
     * @param string|null $expectedAudience Expected `aud` claim value (null to skip check)
     */
    public function __construct(
        #[SensitiveParameter]
        private string $jwtPublicKey,
        private ?string $expectedIssuer = null,
        private ?string $expectedAudience = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '' || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return self::unauthorized('Missing or malformed Authorization header');
        }

        $token = $matches[1];
        $claims = $this->validateJwt($token);

        if ($claims === null) {
            return self::unauthorized('Invalid or expired token');
        }

        $userId = $claims['sub'] ?? null;

        if (!is_string($userId) || $userId === '') {
            return self::unauthorized('Token missing subject claim');
        }

        $authenticatedRequest = $request->withAttribute('user_id', $userId);

        return $handler->handle($authenticatedRequest);
    }

    /**
     * Validate a JWT token and return its claims, or null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function validateJwt(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = base64_decode(strtr($headerB64, '-_', '+/'), true);
        $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        $signature = base64_decode(strtr($signatureB64, '-_', '+/'), true);

        if ($headerJson === false || $payloadJson === false || $signature === false) {
            return null;
        }

        $header = json_decode($headerJson, true);

        if (!is_array($header)) {
            return null;
        }

        if (($header['alg'] ?? '') !== 'RS256' || ($header['typ'] ?? '') !== 'JWT') {
            return null;
        }

        $dataToVerify = $headerB64 . '.' . $payloadB64;

        $publicKey = openssl_pkey_get_public($this->jwtPublicKey);

        if ($publicKey === false) {
            return null;
        }

        $verifyResult = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verifyResult !== 1) {
            return null;
        }

        $decoded = json_decode($payloadJson, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $claims */
        $claims = $decoded;

        $now = time();

        if (isset($claims['exp']) && ((int) (is_numeric($claims['exp']) ? $claims['exp'] : 0)) + self::CLOCK_SKEW_SECONDS < $now) {
            return null;
        }

        if (isset($claims['nbf']) && ((int) (is_numeric($claims['nbf']) ? $claims['nbf'] : 0)) - self::CLOCK_SKEW_SECONDS > $now) {
            return null;
        }

        if ($this->expectedIssuer !== null) {
            if (!isset($claims['iss']) || !is_string($claims['iss']) || !hash_equals($this->expectedIssuer, $claims['iss'])) {
                return null;
            }
        }

        if ($this->expectedAudience !== null) {
            if (!isset($claims['aud']) || !is_string($claims['aud']) || !hash_equals($this->expectedAudience, $claims['aud'])) {
                return null;
            }
        }

        return $claims;
    }

    private static function unauthorized(string $message): ResponseInterface
    {
        return Response::json(['error' => $message], 401);
    }
}
