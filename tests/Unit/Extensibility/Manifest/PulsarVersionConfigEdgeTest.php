<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Manifest\PulsarVersionConfig;

#[CoversClass(PulsarVersionConfig::class)]
final class PulsarVersionConfigEdgeTest extends TestCase
{
    #[Test]
    public function isSatisfiedByExactMinVersion(): void
    {
        $config = new PulsarVersionConfig(minVersion: '1.0.0');

        self::assertTrue($config->isSatisfiedBy('1.0.0'));
    }

    #[Test]
    public function isSatisfiedByHigherVersion(): void
    {
        $config = new PulsarVersionConfig(minVersion: '1.0.0');

        self::assertTrue($config->isSatisfiedBy('2.5.0'));
    }

    #[Test]
    public function isSatisfiedByRejectsLowerVersion(): void
    {
        $config = new PulsarVersionConfig(minVersion: '2.0.0');

        self::assertFalse($config->isSatisfiedBy('1.9.9'));
    }

    #[Test]
    public function isSatisfiedByWithMaxVersionConstraint(): void
    {
        $config = new PulsarVersionConfig(minVersion: '1.0.0', maxVersion: '2.0.0');

        self::assertTrue($config->isSatisfiedBy('1.5.0'));
        self::assertFalse($config->isSatisfiedBy('2.1.0'));
    }

    #[Test]
    public function isSatisfiedByExactMaxVersion(): void
    {
        $config = new PulsarVersionConfig(minVersion: '1.0.0', maxVersion: '2.0.0');

        self::assertTrue($config->isSatisfiedBy('2.0.0'));
    }

    #[Test]
    public function isSatisfiedByCurrentReturnsTrue(): void
    {
        // Current version is 1.0.0, so min 0.0.0 should be satisfied
        $config = new PulsarVersionConfig(minVersion: '0.0.0');

        self::assertTrue($config->isSatisfiedByCurrent());
    }

    #[Test]
    public function isSatisfiedByCurrentWithFutureMinReturnsFalse(): void
    {
        $config = new PulsarVersionConfig(minVersion: '99.99.99');

        self::assertFalse($config->isSatisfiedByCurrent());
    }

    /**
     * F3.12: empty manifest input falls back to '0.0.0' but
     * emits an E_USER_DEPRECATED notice. The
     * `PulsarVersionConfigTest::fromArrayWithMissingMinVersionEmitsDeprecation`
     * test owns the deprecation assertion shape; this edge
     * test just keeps the fallback value pinned.
     */
    #[Test]
    public function fromArrayWithEmptyDataFallsBackToZero(): void
    {
        $previous = set_error_handler(static fn(): bool => true);
        try {
            $config = PulsarVersionConfig::fromArray([]);
            self::assertSame('0.0.0', $config->minVersion);
            self::assertNull($config->maxVersion);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = PulsarVersionConfig::fromArray([
            'min_version' => '1.0.0',
            'max_version' => '2.0.0',
        ]);

        self::assertSame('1.0.0', $config->minVersion);
        self::assertSame('2.0.0', $config->maxVersion);
    }

    #[Test]
    public function toStringWithoutMaxVersion(): void
    {
        $config = new PulsarVersionConfig(minVersion: '1.0.0');

        self::assertSame('>= 1.0.0', $config->toString());
    }

    #[Test]
    public function toStringWithMaxVersion(): void
    {
        $config = new PulsarVersionConfig(minVersion: '1.0.0', maxVersion: '2.0.0');

        self::assertSame('1.0.0 - 2.0.0', $config->toString());
    }

    /**
     * @return iterable<string, array{string, string, ?string, bool}>
     */
    public static function versionConstraintProvider(): iterable
    {
        yield 'at minimum' => ['1.0.0', '1.0.0', null, true];
        yield 'above minimum' => ['1.5.0', '1.0.0', null, true];
        yield 'below minimum' => ['0.9.0', '1.0.0', null, false];
        yield 'within range' => ['1.5.0', '1.0.0', '2.0.0', true];
        yield 'above max' => ['3.0.0', '1.0.0', '2.0.0', false];
        yield 'at max boundary' => ['2.0.0', '1.0.0', '2.0.0', true];
    }

    #[Test]
    #[DataProvider('versionConstraintProvider')]
    public function versionConstraintsWork(string $version, string $min, ?string $max, bool $expected): void
    {
        $config = new PulsarVersionConfig(minVersion: $min, maxVersion: $max);

        self::assertSame($expected, $config->isSatisfiedBy($version));
    }
}
