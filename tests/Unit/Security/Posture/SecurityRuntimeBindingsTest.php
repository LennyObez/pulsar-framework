<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Posture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\MasterKeyFailure;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Posture\SecurityRuntimeBindings;
use Pulsar\Security\Session\SessionEncryption;

use function str_repeat;

#[CoversClass(SecurityRuntimeBindings::class)]
#[CoversClass(MasterKeyFailure::class)]
final class SecurityRuntimeBindingsTest extends TestCase
{
    #[Test]
    public function anEmptyContainerReportsNothingBoundAndNoFailure(): void
    {
        $bindings = SecurityRuntimeBindings::observe(new Container());

        self::assertFalse($bindings->masterKeyBound);
        self::assertFalse($bindings->encryptorBound);
        self::assertFalse($bindings->sessionEncryptionBound);
        self::assertNull($bindings->masterKeyFailure);
    }

    #[Test]
    public function aWiredCryptoStackIsReportedBound(): void
    {
        $key = MasterKey::fromHex(str_repeat('a1', 32));

        $container = new Container();
        $container->instance(MasterKey::class, $key);
        $container->instance(EncryptorInterface::class, Encryptor::fromMasterKey($key, new SodiumCipherSuite()));
        $container->instance(SessionEncryption::class, SessionEncryption::fromMasterKey($key));

        $bindings = SecurityRuntimeBindings::observe($container);

        self::assertTrue($bindings->masterKeyBound);
        self::assertTrue($bindings->encryptorBound);
        self::assertTrue($bindings->sessionEncryptionBound);
        self::assertNull($bindings->masterKeyFailure);
    }

    /**
     * The distinction the whole type exists for: a container that never received
     * a key and one whose key was refused hold exactly the same (absent)
     * bindings, and only the recorded failure tells them apart.
     */
    #[Test]
    public function aRecordedRejectionIsReadBackOffTheContainer(): void
    {
        $container = new Container();
        $container->instance(MasterKeyFailure::class, new MasterKeyFailure('Invalid master key: expected 32 bytes, got 4'));

        $bindings = SecurityRuntimeBindings::observe($container);

        self::assertFalse($bindings->masterKeyBound);
        self::assertSame('Invalid master key: expected 32 bytes, got 4', $bindings->masterKeyFailure);
    }

    /**
     * Observation must not build anything. Resolving during the posture
     * preflight would construct services against a container that may still be
     * incomplete, and the container caches singletons — the ordering trap
     * ADR-0045 records for the compliance evidence gatherer.
     */
    #[Test]
    public function observingDoesNotResolveTheServicesItCounts(): void
    {
        $resolutions = 0;

        $container = new Container();
        $container->singleton(EncryptorInterface::class, static function () use (&$resolutions): EncryptorInterface {
            ++$resolutions;

            return Encryptor::fromMasterKey(MasterKey::fromHex(str_repeat('b2', 32)), new SodiumCipherSuite());
        });

        $bindings = SecurityRuntimeBindings::observe($container);

        self::assertTrue($bindings->encryptorBound);
        self::assertSame(0, $resolutions, 'observe() must use has(), never get()');
    }
}
