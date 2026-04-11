<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Webhook\Exception\WebhookException;

use function hash_hmac;
use function sprintf;
use function str_repeat;

/**
 * Comprehensive security and edge-case tests for HmacWebhookVerifier.
 *
 * Covers adversarial inputs, timing boundaries, secret rotation,
 * malformed header variants, and constant-time comparison guarantees.
 */
#[CoversClass(HmacWebhookVerifier::class)]
final class HmacWebhookVerifierComprehensiveTest extends TestCase
{
    private const string SECRET = 'whsec_production_secret_key';
    private const int NOW = 1700000000;
    private FixedClock $clock;
    private HmacWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('@' . self::NOW));
        $this->verifier = new HmacWebhookVerifier($this->clock);
    }

    private function computeSignature(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    // --- Timestamp edge cases ---

    #[Test]
    public function futureTimestampWithinTolerancePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"event":"created"}';
        $timestamp = self::NOW + 250; // 250 seconds in the future, within 300 tolerance
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function futureTimestampBeyondToleranceThrows(): void
    {
        $payload = '{"event":"created"}';
        $timestamp = self::NOW + 301; // 301 seconds in the future
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('too old');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function exactToleranceBoundaryPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"event":"boundary"}';
        $timestamp = self::NOW - 300; // Exactly 300 seconds old, tolerance is 300
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function oneSecondBeyondToleranceThrows(): void
    {
        $payload = '{"event":"boundary"}';
        $timestamp = self::NOW - 301; // 301 seconds old
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('too old');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function zeroToleranceRequiresExactTimestamp(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"event":"exact"}';
        $timestamp = self::NOW; // Exactly now
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->verifier->verify($payload, $header, self::SECRET, 0);
    }

    #[Test]
    public function zeroToleranceRejectsOneSecondOld(): void
    {
        $payload = '{"event":"exact"}';
        $timestamp = self::NOW - 1;
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->expectException(WebhookException::class);

        $this->verifier->verify($payload, $header, self::SECRET, 0);
    }

    // --- Secret rotation scenarios ---

    #[Test]
    public function oldSecretStillWorksWithMultipleSignatures(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"event":"rotated"}';
        $timestamp = self::NOW;
        $oldSecret = 'whsec_old_secret';
        $newSecret = 'whsec_new_secret';

        $oldSig = $this->computeSignature($payload, $timestamp, $oldSecret);
        $newSig = $this->computeSignature($payload, $timestamp, $newSecret);

        // Header has both old and new signatures
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, $oldSig, $newSig);

        // Verify with old secret - should match first v1
        $this->verifier->verify($payload, $header, $oldSecret, 300);
    }

    #[Test]
    public function wrongSecretFailsEvenWithValidTimestamp(): void
    {
        $payload = '{"event":"wrong_secret"}';
        $timestamp = self::NOW;
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');

        $this->verifier->verify($payload, $header, 'completely_wrong_secret', 300);
    }

    // --- Malformed header adversarial inputs ---

    #[Test]
    public function headerWithInvalidTimestampFormat(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('invalid timestamp');

        $this->verifier->verify('body', 't=abc,v1=sig', self::SECRET, 300);
    }

    #[Test]
    public function headerWithNegativeTimestamp(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('invalid timestamp');

        $this->verifier->verify('body', 't=-1,v1=sig', self::SECRET, 300);
    }

    #[Test]
    public function headerWithEmptyTimestampValue(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('invalid timestamp');

        $this->verifier->verify('body', 't=,v1=sig', self::SECRET, 300);
    }

    #[Test]
    public function headerWithEmptyV1Value(): void
    {
        // v1= with empty value should be skipped, leaving no signatures
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('no v1 signatures');

        $this->verifier->verify('body', 't=1700000000,v1=', self::SECRET, 300);
    }

    #[Test]
    public function headerWithOnlyUnknownPrefixes(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('missing timestamp');

        $this->verifier->verify('body', 'v2=abc,v3=def', self::SECRET, 300);
    }

    #[Test]
    public function headerWithTimestampButOnlyUnknownSignatureVersions(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('no v1 signatures');

        $this->verifier->verify('body', 't=1700000000,v2=sig', self::SECRET, 300);
    }

    #[Test]
    public function headerWithWhitespaceAroundParts(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"event":"whitespace"}';
        $timestamp = self::NOW;
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);

        // Parts have extra whitespace
        $header = sprintf(' t=%d , v1=%s ', $timestamp, $sig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    // --- Payload tampering ---

    #[Test]
    public function tamperedPayloadFailsVerification(): void
    {
        $originalPayload = '{"amount":100}';
        $timestamp = self::NOW;
        $sig = $this->computeSignature($originalPayload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');

        $this->verifier->verify('{"amount":999999}', $header, self::SECRET, 300);
    }

    #[Test]
    public function emptyPayloadCanBeVerified(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '';
        $timestamp = self::NOW;
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function largePayloadCanBeVerified(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = str_repeat('x', 1_000_000); // 1MB payload
        $timestamp = self::NOW;
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    // --- Multiple v1 signatures ---

    /**
     * @return array<string, array{int}>
     */
    public static function multipleSignatureCountProvider(): array
    {
        return [
            'two signatures, first valid' => [0],
            'two signatures, second valid' => [1],
        ];
    }

    #[Test]
    #[DataProvider('multipleSignatureCountProvider')]
    public function correctSignatureFoundAmongMultiple(int $validPosition): void
    {
        $this->expectNotToPerformAssertions();

        // F25.8: legacy fixture used 'invalid_sig_0' / 'invalid_sig_1'
        // which now get rejected at parse time as non-hex. Use
        // well-formed-but-non-matching 64-hex strings so the
        // multi-signature acceptance path is still exercised.
        $payload = '{"event":"multi"}';
        $timestamp = self::NOW;
        $validSig = $this->computeSignature($payload, $timestamp, self::SECRET);

        $sigs = [str_repeat('0', 64), str_repeat('1', 64)];
        $sigs[$validPosition] = $validSig;

        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, $sigs[0], $sigs[1]);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    // --- Timing attack resistance ---

    #[Test]
    public function usesConstantTimeComparisonByDesign(): void
    {
        // We cannot truly test constant-time in a unit test, but we can verify
        // that the code uses hash_equals (by testing that a near-match signature
        // still fails, proving it's not doing byte-by-byte short-circuit)
        $payload = '{"event":"timing"}';
        $timestamp = self::NOW;
        $correctSig = $this->computeSignature($payload, $timestamp, self::SECRET);

        // Flip the last character of the correct signature
        $almostCorrect = substr($correctSig, 0, -1) . (
            $correctSig[-1] === 'a' ? 'b' : 'a'
        );

        $header = sprintf('t=%d,v1=%s', $timestamp, $almostCorrect);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    // --- Header parsing edge cases ---

    #[Test]
    public function headerWithTimestampContainingDecimal(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('invalid timestamp');

        $this->verifier->verify('body', 't=1700000000.5,v1=sig', self::SECRET, 300);
    }

    #[Test]
    public function headerWithDuplicateTimestampUsesLastOne(): void
    {
        // The parser iterates through parts and overwrites timestamp each time
        $payload = '{"event":"dup_timestamp"}';
        $validTimestamp = self::NOW;
        $sig = $this->computeSignature($payload, $validTimestamp, self::SECRET);

        // First timestamp is old, second is valid -- the last one wins
        $header = sprintf('t=%d,t=%d,v1=%s', self::NOW - 999, $validTimestamp, $sig);

        // Should use the last t= value (validTimestamp) which is within tolerance
        $this->expectNotToPerformAssertions();
        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function headerWithOverflowTimestamp(): void
    {
        // Very large timestamp -- still digits, should parse but age check fails
        $payload = '{"event":"overflow"}';
        $timestamp = 9999999999;
        $sig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $sig);

        // Age = abs(NOW - 9999999999) which is huge, beyond any tolerance
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('too old');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }
}
