<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Gateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Support\ParametersHasher;

use function strlen;

#[CoversClass(ParametersHasher::class)]
final class ParametersHasherTest extends TestCase
{
    #[Test]
    public function identicalParametersProduceSameHash(): void
    {
        $hash1 = ParametersHasher::hash('createIntent', [
            'amount_minor' => 5000,
            'currency' => 'USD',
            'provider' => 'test',
        ]);

        $hash2 = ParametersHasher::hash('createIntent', [
            'amount_minor' => 5000,
            'currency' => 'USD',
            'provider' => 'test',
        ]);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function differentArrayOrderProducesSameHash(): void
    {
        $hash1 = ParametersHasher::hash('createIntent', [
            'currency' => 'USD',
            'amount_minor' => 5000,
            'provider' => 'test',
        ]);

        $hash2 = ParametersHasher::hash('createIntent', [
            'provider' => 'test',
            'amount_minor' => 5000,
            'currency' => 'USD',
        ]);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function differentParametersProduceDifferentHash(): void
    {
        $hash1 = ParametersHasher::hash('createIntent', [
            'amount_minor' => 5000,
            'currency' => 'USD',
            'provider' => 'test',
        ]);

        $hash2 = ParametersHasher::hash('createIntent', [
            'amount_minor' => 6000,
            'currency' => 'USD',
            'provider' => 'test',
        ]);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function differentOperationProducesDifferentHash(): void
    {
        $params = [
            'intentId' => 'pi_test',
            'provider' => 'test',
        ];

        $hash1 = ParametersHasher::hash('captureIntent', $params);
        $hash2 = ParametersHasher::hash('cancelIntent', $params);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function hashIsSha256Length(): void
    {
        $hash = ParametersHasher::hash('test', ['key' => 'value']);

        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function nestedArraysSortedRecursively(): void
    {
        $hash1 = ParametersHasher::hash('test', [
            'outer' => ['b' => 2, 'a' => 1],
        ]);

        $hash2 = ParametersHasher::hash('test', [
            'outer' => ['a' => 1, 'b' => 2],
        ]);

        self::assertSame($hash1, $hash2);
    }
}
