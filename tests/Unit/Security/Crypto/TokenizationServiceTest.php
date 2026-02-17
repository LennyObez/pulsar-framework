<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\InMemoryTokenStore;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\TokenizationService;
use Pulsar\Security\Exception\SecurityException;

use function ctype_digit;
use function random_bytes;
use function sodium_bin2hex;
use function str_starts_with;
use function strlen;
use function substr;

#[CoversClass(TokenizationService::class)]
final class TokenizationServiceTest extends TestCase
{
    private TokenizationService $service;
    private InMemoryTokenStore $store;

    protected function setUp(): void
    {
        $hex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex);
        $this->store = new InMemoryTokenStore();
        $this->service = new TokenizationService($masterKey, $this->store);
    }

    #[Test]
    public function tokenizeAndDetokenizeRoundTrip(): void
    {
        $original = 'sensitive-data-12345';
        $token = $this->service->tokenize($original, 'generic');
        $recovered = $this->service->detokenize($token);

        self::assertSame($original, $recovered);
    }

    #[Test]
    public function genericTokenHasCorrectPrefix(): void
    {
        $token = $this->service->tokenize('secret', 'email');

        self::assertTrue(str_starts_with($token, 'ptk_'));
        // ptk_ (4) + 32 hex chars = 36 total
        self::assertSame(36, strlen($token));
    }

    #[Test]
    public function tokenizeProducesUniqueTokensForSameInput(): void
    {
        $token1 = $this->service->tokenize('same-data', 'ctx');
        $token2 = $this->service->tokenize('same-data', 'ctx');

        self::assertNotSame($token1, $token2);
    }

    #[Test]
    public function isTokenizedReturnsTrueForKnownToken(): void
    {
        $token = $this->service->tokenize('data', 'ctx');

        self::assertTrue($this->service->isTokenized($token));
    }

    #[Test]
    public function isTokenizedReturnsFalseForUnknownValue(): void
    {
        self::assertFalse($this->service->isTokenized('ptk_0000000000000000000000000000dead'));
        self::assertFalse($this->service->isTokenized(''));
        self::assertFalse($this->service->isTokenized('not-a-token'));
    }

    #[Test]
    public function detokenizeThrowsForUnknownToken(): void
    {
        $this->expectException(SecurityException::class);

        $this->service->detokenize('ptk_nonexistent_token_value_here_00');
    }

    #[Test]
    public function tokenizeThrowsForEmptyData(): void
    {
        $this->expectException(SecurityException::class);

        $this->service->tokenize('', 'ctx');
    }

    // --- PAN Tokenization ---

    #[Test]
    public function panTokenizationPreservesFirstSixLastFour(): void
    {
        $pan = '4111111111111111'; // 16-digit test Visa
        $token = $this->service->tokenize($pan, 'pan');

        // Same length as original PAN
        self::assertSame(strlen($pan), strlen($token));
        // All digits
        self::assertTrue(ctype_digit($token));
        // First 6 preserved
        self::assertSame(substr($pan, 0, 6), substr($token, 0, 6));
        // Last 4 preserved
        self::assertSame(substr($pan, -4), substr($token, -4));
    }

    #[Test]
    public function panTokenizationRoundTrip(): void
    {
        $pan = '5500000000000004'; // Mastercard test PAN
        $token = $this->service->tokenize($pan, 'pan');
        $recovered = $this->service->detokenize($token);

        self::assertSame($pan, $recovered);
    }

    #[Test]
    #[DataProvider('validPanLengths')]
    public function panTokenizationHandlesVariousLengths(string $pan): void
    {
        $token = $this->service->tokenize($pan, 'pan');

        self::assertSame(strlen($pan), strlen($token));
        self::assertTrue(ctype_digit($token));
        self::assertSame(substr($pan, 0, 6), substr($token, 0, 6));
        self::assertSame(substr($pan, -4), substr($token, -4));

        $recovered = $this->service->detokenize($token);
        self::assertSame($pan, $recovered);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPanLengths(): iterable
    {
        yield '13 digits' => ['4111111111111'];
        yield '14 digits' => ['41111111111111'];
        yield '15 digits' => ['411111111111111'];
        yield '16 digits' => ['4111111111111111'];
        yield '17 digits' => ['41111111111111110'];
        yield '18 digits' => ['411111111111111100'];
        yield '19 digits' => ['4111111111111111000'];
    }

    #[Test]
    #[DataProvider('invalidPans')]
    public function panTokenizationRejectsInvalidPans(string $invalidPan): void
    {
        $this->expectException(SecurityException::class);

        $this->service->tokenize($invalidPan, 'pan');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPans(): iterable
    {
        yield 'too short (12 digits)' => ['411111111111'];
        yield 'too long (20 digits)' => ['41111111111111110000'];
        yield 'contains letters' => ['411111111111111a'];
        yield 'contains spaces' => ['4111 1111 1111 1111'];
    }

    #[Test]
    public function panTokenIsRecognizedByIsTokenized(): void
    {
        $pan = '4111111111111111';
        $token = $this->service->tokenize($pan, 'pan');

        self::assertTrue($this->service->isTokenized($token));
    }

    #[Test]
    public function multipleTokensCanBeDetokenizedIndependently(): void
    {
        $data1 = 'secret-one';
        $data2 = 'secret-two';
        $pan = '4111111111111111';

        $token1 = $this->service->tokenize($data1, 'ssn');
        $token2 = $this->service->tokenize($data2, 'email');
        $panToken = $this->service->tokenize($pan, 'pan');

        self::assertSame($data1, $this->service->detokenize($token1));
        self::assertSame($data2, $this->service->detokenize($token2));
        self::assertSame($pan, $this->service->detokenize($panToken));
    }

    #[Test]
    public function tokenMiddleDigitsDifferFromOriginalPan(): void
    {
        // With random middle digits, it's astronomically unlikely that all
        // generated middle digits match the original PAN's middle. We test
        // over several iterations to confirm randomness.
        $pan = '4111111111111111';
        $allSame = true;

        for ($i = 0; $i < 5; $i++) {
            $store = new InMemoryTokenStore();
            $hex = sodium_bin2hex(random_bytes(32));
            $svc = new TokenizationService(MasterKey::fromHex($hex), $store);

            $token = $svc->tokenize($pan, 'pan');
            $originalMiddle = substr($pan, 6, -4);
            $tokenMiddle = substr($token, 6, -4);

            if ($originalMiddle !== $tokenMiddle) {
                $allSame = false;
                break;
            }
        }

        self::assertFalse($allSame, 'Token middle digits should differ from original PAN');
    }

    #[Test]
    public function differentMasterKeysProduceDifferentCiphertexts(): void
    {
        $data = 'sensitive';

        $store1 = new InMemoryTokenStore();
        $svc1 = new TokenizationService(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
            $store1,
        );

        $store2 = new InMemoryTokenStore();
        $svc2 = new TokenizationService(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
            $store2,
        );

        $token1 = $svc1->tokenize($data, 'ctx');
        $token2 = $svc2->tokenize($data, 'ctx');

        // Different tokens
        self::assertNotSame($token1, $token2);

        // Each can detokenize its own
        self::assertSame($data, $svc1->detokenize($token1));
        self::assertSame($data, $svc2->detokenize($token2));
    }

    #[Test]
    public function crossServiceDetokenizationFails(): void
    {
        $store1 = new InMemoryTokenStore();
        $svc1 = new TokenizationService(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
            $store1,
        );

        $token = $svc1->tokenize('secret', 'ctx');

        // A different service with a different store cannot find the token
        $store2 = new InMemoryTokenStore();
        $svc2 = new TokenizationService(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
            $store2,
        );

        $this->expectException(SecurityException::class);
        $svc2->detokenize($token);
    }
}
