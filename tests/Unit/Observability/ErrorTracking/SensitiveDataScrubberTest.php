<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\ErrorTracking;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(SensitiveDataScrubber::class)]
final class SensitiveDataScrubberTest extends TestCase
{
    #[Test]
    public function scrubsPasswordField(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub(['password' => 'secret123', 'name' => 'Alice']);

        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('Alice', $result['name']);
    }

    #[Test]
    public function scrubsTokenField(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub(['api_token' => 'abc123', 'status' => 'ok']);

        self::assertSame('[REDACTED]', $result['api_token']);
        self::assertSame('ok', $result['status']);
    }

    #[Test]
    public function isCaseInsensitive(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub(['API_KEY' => 'my-key', 'Authorization' => 'Bearer xyz']);

        self::assertSame('[REDACTED]', $result['API_KEY']);
        self::assertSame('[REDACTED]', $result['Authorization']);
    }

    #[Test]
    public function scrubsNestedArrays(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub([
            'user' => [
                'name' => 'Alice',
                'password' => 'hidden',
                'details' => [
                    'ssn' => '123-45-6789',
                ],
            ],
        ]);

        /** @var array<string, mixed> $user */
        $user = $result['user'];
        self::assertSame('Alice', $user['name']);
        self::assertSame('[REDACTED]', $user['password']);
        /** @var array<string, mixed> $details */
        $details = $user['details'];
        self::assertSame('[REDACTED]', $details['ssn']);
    }

    #[Test]
    public function scrubsCustomFields(): void
    {
        $scrubber = new SensitiveDataScrubber(['my_secret']);
        $result = $scrubber->scrub(['my_secret_value' => 'hidden', 'public' => 'visible']);

        self::assertSame('[REDACTED]', $result['my_secret_value']);
        self::assertSame('visible', $result['public']);
    }

    #[Test]
    public function scrubsHeaders(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrubHeaders([
            'Authorization' => 'Bearer token',
            'Cookie' => 'session=abc',
            'Content-Type' => 'application/json',
            'X-API-Key' => 'key123',
        ]);

        self::assertSame('[REDACTED]', $result['Authorization']);
        self::assertSame('[REDACTED]', $result['Cookie']);
        self::assertSame('application/json', $result['Content-Type']);
        self::assertSame('[REDACTED]', $result['X-API-Key']);
    }

    #[Test]
    public function emptyArrayReturnsEmpty(): void
    {
        $scrubber = new SensitiveDataScrubber();

        self::assertSame([], $scrubber->scrub([]));
        self::assertSame([], $scrubber->scrubHeaders([]));
    }

    #[Test]
    public function scrubsCreditCardField(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub(['credit_card_number' => '4111111111111111']);

        self::assertSame('[REDACTED]', $result['credit_card_number']);
    }

    /**
     * Field names match on word-boundary segments, not raw substrings.
     * A substring match redacts legitimate metric and config fields
     * that merely share the letters, so `tokenizer` (a single segment)
     * and `tokenization_settings` (segments `tokenization`, `settings`)
     * must survive. Plural forms still match (`auth_tokens` →
     * singularised to `auth_token`) so plural-style sensitive arrays
     * stay scrubbed.
     *
     * Edge cases such as `tokens_per_second` (a rate metric containing
     * the literal segment `tokens`) intentionally still redact: the
     * segment IS the plural of a sensitive field, and over-redacting a
     * metric is preferable to leaking token data through what looks
     * like a benign metric.
     */
    #[Test]
    public function tokenSubstringDoesNotOverMatchTokenizer(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub([
            'tokenizer' => 'bpe',
            'tokenization_settings' => ['max_length' => 512],
            'auth_token' => 'should_be_hidden',
            'auth_tokens' => 'should_be_hidden',
            'accessToken' => 'should_be_hidden',
        ]);

        self::assertSame('bpe', $result['tokenizer']);
        self::assertSame(['max_length' => 512], $result['tokenization_settings']);
        self::assertSame('[REDACTED]', $result['auth_token']);
        self::assertSame('[REDACTED]', $result['auth_tokens']);
        self::assertSame('[REDACTED]', $result['accessToken']);
    }

    #[Test]
    public function camelCaseAndPascalCaseAreSegmented(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub([
            'apiKey' => 'k',
            'APIKey' => 'k',
            'userPassword' => 'p',
            'normalField' => 'visible',
        ]);

        self::assertSame('[REDACTED]', $result['apiKey']);
        self::assertSame('[REDACTED]', $result['APIKey']);
        self::assertSame('[REDACTED]', $result['userPassword']);
        self::assertSame('visible', $result['normalField']);
    }

    #[Test]
    public function multiSegmentFieldRequiresAllSegments(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $result = $scrubber->scrub([
            'private_key_id' => 'should_be_hidden',
            'private_value' => 'visible',
            'key_count' => 1,
        ]);

        self::assertSame('[REDACTED]', $result['private_key_id']);
        self::assertSame('visible', $result['private_value']);
        self::assertSame(1, $result['key_count']);
    }

    /**
     * Stack-trace strings may contain credit-card numbers, JWTs,
     * or long hex / base64 secrets surfaced as method args. The
     * scrubString() helper redacts those patterns so a Throwable
     * formatter can sanitise free-form text.
     */
    #[Test]
    public function scrubStringRedactsCreditCardNumbers(): void
    {
        $scrubber = new SensitiveDataScrubber();

        self::assertStringContainsString(
            '[REDACTED]',
            $scrubber->scrubString('processing card 4111-1111-1111-1111 declined'),
        );
        self::assertStringContainsString(
            '[REDACTED]',
            $scrubber->scrubString('PAN 4111 1111 1111 1111'),
        );
        self::assertStringContainsString(
            '[REDACTED]',
            $scrubber->scrubString('PAN 4111111111111111'),
        );
    }

    #[Test]
    public function scrubStringRedactsJwt(): void
    {
        $scrubber = new SensitiveDataScrubber();

        // Build a JWT-shaped string at runtime so the test file does
        // not embed a literal token (static analyzers would otherwise
        // flag a hard-coded credential even though it's a fixture).
        $header = rtrim(strtr(base64_encode('{"alg":"HS256"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"sub":"1"}'), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $jwt = $header . '.' . $payload . '.' . $signature;

        $result = $scrubber->scrubString("Authorization failed: {$jwt}");

        self::assertStringNotContainsString($jwt, $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function scrubStringRedactsLongHexTokens(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $hex = str_repeat('a', 64);

        $result = $scrubber->scrubString("session={$hex} expired");

        self::assertStringNotContainsString($hex, $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function scrubStringPreservesShortHexAndProse(): void
    {
        $scrubber = new SensitiveDataScrubber();

        self::assertSame('user id 42', $scrubber->scrubString('user id 42'));
        self::assertSame('hex deadbeef', $scrubber->scrubString('hex deadbeef'));
        self::assertSame('order 12345 placed', $scrubber->scrubString('order 12345 placed'));
    }
}
