<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheAllowedClasses;
use Pulsar\Config\ConfigRepository;
use ReflectionClass;
use Serializable;

use function dirname;
use function sprintf;

/**
 * F26.2 regression suite: every class in
 * `CacheAllowedClasses::ALWAYS_ALLOWED` must remain free of the
 * dangerous magic methods that turn a deserialization target into a
 * gadget chain. The historical bypass let `ConfigRepository` join the
 * allowlist without going through `isEligible()` — this test plus the
 * scan-time check in `CacheAllowedClasses::assertAlwaysAllowedSafe()`
 * close that loop.
 */
#[CoversClass(CacheAllowedClasses::class)]
final class CacheAllowedClassesAlwaysAllowedSafetyTest extends TestCase
{
    private const array DANGEROUS_METHODS = [
        '__wakeup',
        '__destruct',
        '__serialize',
        '__unserialize',
    ];

    /**
     * @return list<array{0: class-string}>
     */
    public static function alwaysAllowedClassProvider(): array
    {
        // Mirrors CacheAllowedClasses::ALWAYS_ALLOWED. If that list
        // grows, this test data provider grows with it — adding a new
        // entry there without here would simply leave the new class
        // untested.
        return [
            [ConfigRepository::class],
        ];
    }

    #[Test]
    public function configRepositoryHasNoDangerousMagicMethods(): void
    {
        $ref = new ReflectionClass(ConfigRepository::class);

        foreach (self::DANGEROUS_METHODS as $method) {
            $hasOwnMethod = $ref->hasMethod($method)
                && $ref->getMethod($method)->getDeclaringClass()->getName() === $ref->getName();

            self::assertFalse(
                $hasOwnMethod,
                sprintf(
                    'F26.2: ConfigRepository defines %s — it is on ALWAYS_ALLOWED, '
                        . 'so this method would run on every cache unserialize() and '
                        . 'become a deserialization gadget. Either remove the magic '
                        . 'method or remove ConfigRepository from ALWAYS_ALLOWED.',
                    $method,
                ),
            );
        }
    }

    #[Test]
    public function configRepositoryDoesNotImplementSerializable(): void
    {
        $ref = new ReflectionClass(ConfigRepository::class);

        self::assertFalse(
            $ref->implementsInterface(Serializable::class),
            'F26.2: ConfigRepository implements Serializable — its custom '
                . 'unserialize() codepath is not constrained by allowed_classes, '
                . 'so the class must not be on ALWAYS_ALLOWED with this interface.',
        );
    }

    #[Test]
    public function scanWalksEveryAlwaysAllowedClassThroughTheSafetyGuard(): void
    {
        // End-to-end: the production scan() entry point must not raise
        // for the current shipping ALWAYS_ALLOWED set. If a future PR
        // adds a dangerous magic method to one of those classes,
        // scan() raises CacheException::alwaysAllowedClassUnsafe()
        // and this assertion fails — no need for a separate runtime
        // guard.
        $vendorPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor';
        $srcPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';

        $result = CacheAllowedClasses::scan($vendorPath, $srcPath);

        self::assertContains(ConfigRepository::class, $result);
    }
}
