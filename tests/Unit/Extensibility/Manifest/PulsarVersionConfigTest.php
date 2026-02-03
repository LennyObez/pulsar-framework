<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Manifest\PulsarVersionConfig;

#[CoversClass(PulsarVersionConfig::class)]
final class PulsarVersionConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $config = new PulsarVersionConfig('1.0.0', '2.0.0');

        self::assertSame('1.0.0', $config->minVersion);
        self::assertSame('2.0.0', $config->maxVersion);
    }

    #[Test]
    public function constructorDefaultsMaxVersionToNull(): void
    {
        $config = new PulsarVersionConfig('1.0.0');

        self::assertNull($config->maxVersion);
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
    public function fromArrayWithDefaults(): void
    {
        $config = PulsarVersionConfig::fromArray([]);

        self::assertSame('0.0.0', $config->minVersion);
        self::assertNull($config->maxVersion);
    }

    #[Test]
    public function isSatisfiedByWithinRange(): void
    {
        $config = new PulsarVersionConfig('1.0.0', '2.0.0');

        self::assertTrue($config->isSatisfiedBy('1.5.0'));
        self::assertTrue($config->isSatisfiedBy('1.0.0'));
        self::assertTrue($config->isSatisfiedBy('2.0.0'));
    }

    #[Test]
    public function isSatisfiedByBelowMinimum(): void
    {
        $config = new PulsarVersionConfig('1.0.0', '2.0.0');

        self::assertFalse($config->isSatisfiedBy('0.9.0'));
    }

    #[Test]
    public function isSatisfiedByAboveMaximum(): void
    {
        $config = new PulsarVersionConfig('1.0.0', '2.0.0');

        self::assertFalse($config->isSatisfiedBy('2.1.0'));
    }

    #[Test]
    public function isSatisfiedByWithNoMaxVersion(): void
    {
        $config = new PulsarVersionConfig('1.0.0');

        self::assertTrue($config->isSatisfiedBy('99.0.0'));
        self::assertFalse($config->isSatisfiedBy('0.9.0'));
    }

    #[Test]
    public function isSatisfiedByCurrentDelegatesToIsSatisfiedBy(): void
    {
        $config = new PulsarVersionConfig('0.0.1');

        self::assertTrue($config->isSatisfiedByCurrent());
    }

    #[Test]
    public function toStringWithMaxVersion(): void
    {
        $config = new PulsarVersionConfig('1.0.0', '2.0.0');

        self::assertSame('1.0.0 - 2.0.0', $config->toString());
    }

    #[Test]
    public function toStringWithoutMaxVersion(): void
    {
        $config = new PulsarVersionConfig('1.0.0');

        self::assertSame('>= 1.0.0', $config->toString());
    }
}
