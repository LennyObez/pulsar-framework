<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\Privacy\Internal\HmacPseudonymizer;

use function random_bytes;
use function strlen;

#[CoversClass(HmacPseudonymizer::class)]
final class HmacPseudonymizerTest extends TestCase
{
    private HmacPseudonymizer $pseudonymizer;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['pseudonymizer' => $key]);
        $this->pseudonymizer = new HmacPseudonymizer($keyRing);
    }

    #[Test]
    public function pseudonymizeReturnsDeterministicResult(): void
    {
        $p1 = $this->pseudonymizer->pseudonymize('device-123', 'device_id');
        $p2 = $this->pseudonymizer->pseudonymize('device-123', 'device_id');

        self::assertSame($p1, $p2);
    }

    #[Test]
    public function pseudonymizeProducesDifferentResultsForDifferentValues(): void
    {
        $p1 = $this->pseudonymizer->pseudonymize('device-1', 'device_id');
        $p2 = $this->pseudonymizer->pseudonymize('device-2', 'device_id');

        self::assertNotSame($p1, $p2);
    }

    #[Test]
    public function pseudonymizeProducesDifferentResultsForDifferentContexts(): void
    {
        $p1 = $this->pseudonymizer->pseudonymize('value', 'device_id');
        $p2 = $this->pseudonymizer->pseudonymize('value', 'ip_address');

        self::assertNotSame($p1, $p2);
    }

    #[Test]
    public function pseudonymizeIncludesContextPrefix(): void
    {
        $result = $this->pseudonymizer->pseudonymize('device-123', 'device_id');

        self::assertStringStartsWith('pseudo:device_id:', $result);
    }

    #[Test]
    public function pseudonymizeReturnsPlaceholderWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $pseudonymizer = new HmacPseudonymizer($keyRing);

        $result = $pseudonymizer->pseudonymize('device-123', 'device_id');

        self::assertSame('pseudo:device_id:unavailable', $result);
    }

    #[Test]
    public function rotationSaltChangesOutput(): void
    {
        $p1 = $this->pseudonymizer->pseudonymize('device-123', 'device_id');

        $rotated = $this->pseudonymizer->withRotationSalt('new-salt-2024');
        $p2 = $rotated->pseudonymize('device-123', 'device_id');

        self::assertNotSame($p1, $p2);
    }

    #[Test]
    public function sameSaltProducesSameResult(): void
    {
        $rotated = $this->pseudonymizer->withRotationSalt('salt-v1');

        $p1 = $rotated->pseudonymize('device-123', 'device_id');
        $p2 = $rotated->pseudonymize('device-123', 'device_id');

        self::assertSame($p1, $p2);
    }

    #[Test]
    public function pseudonymizeWithDifferentKeysProducesDifferentResults(): void
    {
        $key1 = random_bytes(32);
        $key2 = random_bytes(32);

        $p1 = new HmacPseudonymizer(new EnvKeyRing(['pseudonymizer' => $key1]));
        $p2 = new HmacPseudonymizer(new EnvKeyRing(['pseudonymizer' => $key2]));

        self::assertNotSame(
            $p1->pseudonymize('value', 'ctx'),
            $p2->pseudonymize('value', 'ctx'),
        );
    }

    #[Test]
    public function pseudonymizeOutputIsFixedLength(): void
    {
        $p1 = $this->pseudonymizer->pseudonymize('short', 'ctx');
        $p2 = $this->pseudonymizer->pseudonymize('a very long device identifier string', 'ctx');

        // Both should have same format: pseudo:ctx:<16 hex chars>
        self::assertSame(strlen($p1), strlen($p2));
    }
}
