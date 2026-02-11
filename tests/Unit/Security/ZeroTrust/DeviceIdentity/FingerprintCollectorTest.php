<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\FingerprintCollector;

use function random_bytes;
use function str_repeat;

#[CoversClass(FingerprintCollector::class)]
#[CoversClass(DeviceProofResult::class)]
final class FingerprintCollectorTest extends TestCase
{
    private FingerprintCollector $collector;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-fingerprint' => $key]);
        $this->collector = new FingerprintCollector($keyRing);
    }

    // ── Successful collection ──────────────────────────────────────────

    #[Test]
    public function collectProducesVerifiedResultWithAllHeaders(): void
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
            'Accept' => 'text/html',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        $result = $this->collector->collect($headers);

        self::assertTrue($result->verified);
        self::assertSame(0.3, $result->confidence);
        self::assertNotEmpty($result->deviceId);
    }

    #[Test]
    public function collectSucceedsWithSingleValidHeader(): void
    {
        $result = $this->collector->collect(['User-Agent' => 'Chrome']);

        self::assertTrue($result->verified);
        self::assertNotEmpty($result->deviceId);
    }

    #[Test]
    public function collectSucceedsWithOnlyAcceptHeader(): void
    {
        $result = $this->collector->collect(['Accept' => 'text/html']);

        self::assertTrue($result->verified);
        self::assertNotEmpty($result->deviceId);
    }

    #[Test]
    public function collectSucceedsWithOnlyAcceptLanguage(): void
    {
        $result = $this->collector->collect(['Accept-Language' => 'en-US']);

        self::assertTrue($result->verified);
    }

    #[Test]
    public function collectSucceedsWithOnlyAcceptEncoding(): void
    {
        $result = $this->collector->collect(['Accept-Encoding' => 'gzip']);

        self::assertTrue($result->verified);
    }

    // ── Confidence cap ─────────────────────────────────────────────────

    #[Test]
    public function collectConfidenceIsCappedAtPointThree(): void
    {
        $headers = [
            'User-Agent' => 'Chrome',
            'Accept' => '*/*',
            'Accept-Language' => 'en',
            'Accept-Encoding' => 'gzip, deflate, br',
        ];

        $result = $this->collector->collect($headers);

        self::assertTrue($result->verified);
        self::assertSame(0.3, $result->confidence);
    }

    // ── Deterministic hashing ──────────────────────────────────────────

    #[Test]
    public function collectProducesDeterministicHash(): void
    {
        $headers = [
            'User-Agent' => 'TestBrowser/1.0',
            'Accept' => 'text/html',
        ];

        $r1 = $this->collector->collect($headers);
        $r2 = $this->collector->collect($headers);

        self::assertSame($r1->deviceId, $r2->deviceId);
    }

    #[Test]
    public function collectProducesDifferentHashForDifferentHeaders(): void
    {
        $r1 = $this->collector->collect(['User-Agent' => 'Browser-A']);
        $r2 = $this->collector->collect(['User-Agent' => 'Browser-B']);

        self::assertNotSame($r1->deviceId, $r2->deviceId);
    }

    #[Test]
    public function differentKeysProduceDifferentHashes(): void
    {
        $key2 = random_bytes(32);
        $keyRing2 = new EnvKeyRing(['device-fingerprint' => $key2]);
        $collector2 = new FingerprintCollector($keyRing2);

        $headers = ['User-Agent' => 'SameAgent'];

        $r1 = $this->collector->collect($headers);
        $r2 = $collector2->collect($headers);

        self::assertNotSame($r1->deviceId, $r2->deviceId);
    }

    // ── Case insensitivity ─────────────────────────────────────────────

    #[Test]
    public function collectIsCaseInsensitiveForHeaderNames(): void
    {
        $r1 = $this->collector->collect(['User-Agent' => 'Test']);
        $r2 = $this->collector->collect(['user-agent' => 'Test']);

        self::assertSame($r1->deviceId, $r2->deviceId);
    }

    #[Test]
    public function collectIsCaseInsensitiveForAllHeaders(): void
    {
        $r1 = $this->collector->collect([
            'ACCEPT' => 'text/html',
            'ACCEPT-LANGUAGE' => 'en',
        ]);
        $r2 = $this->collector->collect([
            'accept' => 'text/html',
            'accept-language' => 'en',
        ]);

        self::assertSame($r1->deviceId, $r2->deviceId);
    }

    #[Test]
    public function collectPreservesHeaderValueCase(): void
    {
        $r1 = $this->collector->collect(['User-Agent' => 'Mozilla/5.0']);
        $r2 = $this->collector->collect(['User-Agent' => 'mozilla/5.0']);

        // Header values are NOT lowercased, so different case = different hash
        self::assertNotSame($r1->deviceId, $r2->deviceId);
    }

    // ── Empty/missing header handling ──────────────────────────────────

    #[Test]
    public function collectFailsWithEmptyHeaders(): void
    {
        $result = $this->collector->collect([]);

        self::assertFalse($result->verified);
        self::assertSame('No fingerprint components available', $result->reason);
        self::assertSame(0.0, $result->confidence);
    }

    #[Test]
    public function collectFailsWithIrrelevantHeaders(): void
    {
        $headers = [
            'X-Custom-Header' => 'value',
            'Authorization' => 'Bearer token',
            'Content-Type' => 'application/json',
        ];

        $result = $this->collector->collect($headers);

        self::assertFalse($result->verified);
        self::assertSame('No fingerprint components available', $result->reason);
    }

    #[Test]
    public function collectIgnoresEmptyHeaderValues(): void
    {
        $headers = [
            'User-Agent' => '',
            'Accept' => 'text/html',
        ];

        $result = $this->collector->collect($headers);

        self::assertTrue($result->verified);
    }

    #[Test]
    public function collectIgnoresWhitespaceOnlyHeaderValues(): void
    {
        $headers = [
            'User-Agent' => '   ',
            'Accept' => '  ',
            'Accept-Language' => "\t",
            'Accept-Encoding' => "\n",
        ];

        $result = $this->collector->collect($headers);

        // All values are whitespace-only, trim makes them empty, so no components
        self::assertFalse($result->verified);
        self::assertSame('No fingerprint components available', $result->reason);
    }

    #[Test]
    public function collectAllEmptyRelevantHeadersReturnsFailed(): void
    {
        $headers = [
            'User-Agent' => '',
            'Accept' => '',
            'Accept-Language' => '',
            'Accept-Encoding' => '',
        ];

        $result = $this->collector->collect($headers);

        self::assertFalse($result->verified);
        self::assertSame('No fingerprint components available', $result->reason);
    }

    // ── Non-fingerprint headers excluded ───────────────────────────────

    #[Test]
    public function collectUsesOnlyStandardFingerprintHeaders(): void
    {
        $withExtra = $this->collector->collect([
            'User-Agent' => 'Test',
            'X-Forwarded-For' => '1.2.3.4',
        ]);

        $withoutExtra = $this->collector->collect([
            'User-Agent' => 'Test',
        ]);

        self::assertSame($withExtra->deviceId, $withoutExtra->deviceId);
    }

    #[Test]
    public function collectDoesNotIncludeCookieHeaders(): void
    {
        $r1 = $this->collector->collect([
            'User-Agent' => 'Test',
            'Cookie' => 'session=abc123',
        ]);

        $r2 = $this->collector->collect([
            'User-Agent' => 'Test',
            'Cookie' => 'session=different',
        ]);

        // Cookie header is not a fingerprint component, so hashes should be identical
        self::assertSame($r1->deviceId, $r2->deviceId);
    }

    // ── Key unavailability ─────────────────────────────────────────────

    #[Test]
    public function collectFailsWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $collector = new FingerprintCollector($keyRing);

        $result = $collector->collect(['User-Agent' => 'Test']);

        self::assertFalse($result->verified);
        self::assertStringContainsString('key unavailable', $result->reason);
        self::assertSame(0.0, $result->confidence);
    }

    // ── Custom key ID ──────────────────────────────────────────────────

    #[Test]
    public function usesCustomKeyId(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['custom-fp-key' => $key]);
        $collector = new FingerprintCollector($keyRing, keyId: 'custom-fp-key');

        $result = $collector->collect(['User-Agent' => 'Test']);

        self::assertTrue($result->verified);
    }

    #[Test]
    public function failsWithMismatchedKeyId(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['some-key' => $key]);
        $collector = new FingerprintCollector($keyRing, keyId: 'wrong-key');

        $result = $collector->collect(['User-Agent' => 'Test']);

        self::assertFalse($result->verified);
        self::assertStringContainsString('key unavailable', $result->reason);
    }

    // ── Edge cases ─────────────────────────────────────────────────────

    #[Test]
    public function collectWithVeryLongUserAgentWorks(): void
    {
        $longUA = 'Mozilla/5.0 ' . str_repeat('(compatible; CustomBot/1.0) ', 500);
        $result = $this->collector->collect(['User-Agent' => $longUA]);

        self::assertTrue($result->verified);
        self::assertNotEmpty($result->deviceId);
    }

    #[Test]
    public function collectWithUnicodeHeaderValuesWorks(): void
    {
        $result = $this->collector->collect([
            'Accept-Language' => 'zh-CN,zh;q=0.9,ja;q=0.8',
        ]);

        self::assertTrue($result->verified);
    }

    /**
     * @return array<string, array{array<string, string>, array<string, string>}>
     */
    public static function headerSubsetProvider(): array
    {
        return [
            'only User-Agent vs all' => [
                ['User-Agent' => 'Test'],
                ['User-Agent' => 'Test', 'Accept' => 'text/html'],
            ],
            'only Accept vs all' => [
                ['Accept' => 'text/html'],
                ['Accept' => 'text/html', 'Accept-Language' => 'en'],
            ],
        ];
    }

    /**
     * @param array<string, string> $subset
     * @param array<string, string> $superset
     */
    #[Test]
    #[DataProvider('headerSubsetProvider')]
    public function differentHeaderSubsetsProduceDifferentFingerprints(
        array $subset,
        array $superset,
    ): void {
        $r1 = $this->collector->collect($subset);
        $r2 = $this->collector->collect($superset);

        self::assertTrue($r1->verified);
        self::assertTrue($r2->verified);
        self::assertNotSame($r1->deviceId, $r2->deviceId);
    }
}
