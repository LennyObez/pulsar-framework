<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\IssuerPublicKey;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivateAccessTokenVerifier;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;

use function base64_decode;
use function base64_encode;
use function chr;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function ord;
use function preg_replace;
use function rtrim;
use function strtr;
use function substr;

use const OPENSSL_KEYTYPE_RSA;

#[CoversClass(PrivateAccessTokenVerifier::class)]
#[CoversClass(IssuerPublicKey::class)]
#[RequiresPhpExtension('gmp')]
final class PrivateAccessTokenVerifierTest extends TestCase
{
    #[Test]
    public function acceptsTheOfficialRfc9578TestVector(): void
    {
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(Rfc9578TestVector::spkiBase64Url());

        self::assertTrue(
            $verifier->verify(Rfc9578TestVector::tokenBase64Url(), Rfc9578TestVector::challenge()),
            'the RFC 9578 type-0x0002 known-answer token must verify',
        );
    }

    #[Test]
    public function rejectsATokenWithATamperedAuthenticator(): void
    {
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(Rfc9578TestVector::spkiBase64Url());

        $token = Rfc9578TestVector::token();
        $token[200] = chr((ord($token[200]) ^ 0xFF) & 0xFF); // flip a byte in the signature

        self::assertFalse($verifier->verify($this->base64Url($token), Rfc9578TestVector::challenge()));
    }

    #[Test]
    public function rejectsATokenBoundToADifferentChallenge(): void
    {
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(Rfc9578TestVector::spkiBase64Url());

        $wrongOrigin = new TokenChallenge(0x0002, 'issuer.example', 'attacker.example');

        self::assertFalse($verifier->verify(Rfc9578TestVector::tokenBase64Url(), $wrongOrigin));
    }

    #[Test]
    public function rejectsATokenSignedUnderADifferentKey(): void
    {
        // A freshly generated, unrelated 2048-bit RSA key: its token_key_id
        // (SHA-256 of its SPKI) differs, so the vector's token selects no key
        // and is rejected before any signature check.
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey($this->base64Url($this->freshRsaSpkiDer()));

        self::assertFalse($verifier->verify(Rfc9578TestVector::tokenBase64Url(), Rfc9578TestVector::challenge()));
    }

    #[Test]
    public function verifiesTokensUnderAnyConfiguredKeyForRotation(): void
    {
        // Two issuers' keys trusted at once; a token signed by the second must
        // verify (the verifier selects the key by the token's key id).
        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');
        $issuedA = PrivacyPassTokenFactory::issue($challenge);
        $issuedB = PrivacyPassTokenFactory::issue($challenge);

        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKeys([
            $this->base64Url($issuedA['spkiDer']),
            $this->base64Url($issuedB['spkiDer']),
        ]);

        self::assertTrue($verifier->verify($this->base64Url($issuedA['token']), $challenge));
        self::assertTrue($verifier->verify($this->base64Url($issuedB['token']), $challenge));
    }

    #[Test]
    public function enforcesSingleUseWhenACacheIsBound(): void
    {
        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');
        $issued = PrivacyPassTokenFactory::issue($challenge);

        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(
            $this->base64Url($issued['spkiDer']),
            $this->inMemoryCache(),
            singleUse: true,
        );

        $token = $this->base64Url($issued['token']);

        self::assertTrue($verifier->verify($token, $challenge), 'first redemption is accepted');
        self::assertFalse($verifier->verify($token, $challenge), 'replay of the same token is rejected');
    }

    #[Test]
    public function allowsReuseWhenSingleUseDisabledOrNoCache(): void
    {
        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');
        $issued = PrivacyPassTokenFactory::issue($challenge);
        $token = $this->base64Url($issued['token']);

        // No cache bound: degrades to reuse-within-lifetime.
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey($this->base64Url($issued['spkiDer']), singleUse: true);

        self::assertTrue($verifier->verify($token, $challenge));
        self::assertTrue($verifier->verify($token, $challenge), 'reuse allowed without a replay cache');
    }

    #[Test]
    public function fromBase64UrlKeysRejectsAnEmptyKeySet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) PrivateAccessTokenVerifier::fromBase64UrlKeys([]);
    }

    private function freshRsaSpkiDer(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($key, 'failed to generate RSA key');

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $pem = $details['key'];
        self::assertIsString($pem);

        $base64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        self::assertIsString($base64);
        $der = base64_decode($base64, true);
        self::assertNotFalse($der);

        return $der;
    }

    #[Test]
    public function acceptsAFreshlyIssuedTokenForAStatelessChallenge(): void
    {
        // Round-trip against a self-issued token bound to an empty-context
        // challenge — the production path the single RFC vector cannot cover.
        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');
        $issued = PrivacyPassTokenFactory::issue($challenge);

        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey($this->base64Url($issued['spkiDer']));

        self::assertTrue($verifier->verify($this->base64Url($issued['token']), $challenge));

        $wrong = new TokenChallenge(0x0002, 'issuer.example', 'attacker.example');
        self::assertFalse($verifier->verify($this->base64Url($issued['token']), $wrong));
    }

    #[Test]
    public function rejectsGarbageInput(): void
    {
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(Rfc9578TestVector::spkiBase64Url());

        self::assertFalse($verifier->verify('not-a-valid-token', Rfc9578TestVector::challenge()));
        self::assertFalse($verifier->verify('', Rfc9578TestVector::challenge()));
    }

    #[Test]
    public function rejectsATokenOfTheWrongLength(): void
    {
        $verifier = PrivateAccessTokenVerifier::fromBase64UrlKey(Rfc9578TestVector::spkiBase64Url());

        $truncated = substr(Rfc9578TestVector::token(), 0, 100);

        self::assertFalse($verifier->verify($this->base64Url($truncated), Rfc9578TestVector::challenge()));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function inMemoryCache(): TaggedCacheInterface
    {
        return new class implements TaggedCacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void {}

            public function invalidateTags(array $tags): void {}
        };
    }
}
