<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Manifest\PulsarVersionConfig;

#[CoversClass(PulsarVersionConfig::class)]
final class PulsarVersionConfigTest extends TestCase
{
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

    /**
     * F3.12: a manifest without `pulsar.min_version` triggers
     * an E_USER_DEPRECATED notice (will become a hard
     * exception in the next major). For now the default
     * remains '0.0.0' so the 74 in-tree fixture call sites
     * keep working.
     */
    #[Test]
    public function fromArrayWithMissingMinVersionEmitsDeprecation(): void
    {
        $previous = set_error_handler(static function (int $errno, string $msg): bool {
            if ($errno === E_USER_DEPRECATED && str_contains($msg, 'pulsar.min_version')) {
                throw new InvalidArgumentException($msg);
            }
            return false;
        });

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageIsOrContains('pulsar.min_version');

            (void) PulsarVersionConfig::fromArray([]);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * F3.12: when `min_version` is supplied, no deprecation
     * fires and the value round-trips unchanged.
     */
    #[Test]
    public function fromArrayWithExplicitMinVersionDoesNotEmitDeprecation(): void
    {
        $deprecations = [];
        $previous = set_error_handler(static function (int $errno, string $msg) use (&$deprecations): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecations[] = $msg;
            }
            return false;
        });

        try {
            $config = PulsarVersionConfig::fromArray(['min_version' => '1.2.3']);
            self::assertSame('1.2.3', $config->minVersion);
            self::assertSame([], $deprecations);
        } finally {
            restore_error_handler();
        }
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
