<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\FingerprintCollector;

use function random_bytes;

#[CoversClass(FingerprintCollector::class)]
final class FingerprintCollectorTest extends TestCase
{
    private FingerprintCollector $collector;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-fingerprint' => $key]);
        $this->collector = new FingerprintCollector($keyRing);
    }

    #[Test]
    public function collectProducesVerifiedResultWithHeaders(): void
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
        self::assertNotEmpty($result->deviceId); // The fingerprint hash
    }

    #[Test]
    public function collectConfidenceIsCappedAtPointThree(): void
    {
        $headers = ['User-Agent' => 'Chrome', 'Accept' => '*/*'];

        $result = $this->collector->collect($headers);

        self::assertTrue($result->verified);
        self::assertLessThanOrEqual(0.3, $result->confidence);
    }

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
    public function collectIsCaseInsensitiveForHeaderNames(): void
    {
        $r1 = $this->collector->collect(['User-Agent' => 'Test']);
        $r2 = $this->collector->collect(['user-agent' => 'Test']);

        self::assertSame($r1->deviceId, $r2->deviceId);
    }

    #[Test]
    public function collectFailsWithEmptyHeaders(): void
    {
        $result = $this->collector->collect([]);

        self::assertFalse($result->verified);
        self::assertSame('No fingerprint components available', $result->reason);
    }

    #[Test]
    public function collectFailsWithIrrelevantHeaders(): void
    {
        $headers = [
            'X-Custom-Header' => 'value',
            'Authorization' => 'Bearer token',
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
    public function collectFailsWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $collector = new FingerprintCollector($keyRing);

        $result = $collector->collect(['User-Agent' => 'Test']);

        self::assertFalse($result->verified);
        self::assertStringContainsString('key unavailable', $result->reason);
    }

    #[Test]
    public function collectUsesOnlyStandardFingerprintHeaders(): void
    {
        // Only standard fingerprint headers should contribute
        $withExtra = $this->collector->collect([
            'User-Agent' => 'Test',
            'X-Forwarded-For' => '1.2.3.4',
        ]);

        $withoutExtra = $this->collector->collect([
            'User-Agent' => 'Test',
        ]);

        // The extra non-fingerprint header should not change the result
        self::assertSame($withExtra->deviceId, $withoutExtra->deviceId);
    }
}
