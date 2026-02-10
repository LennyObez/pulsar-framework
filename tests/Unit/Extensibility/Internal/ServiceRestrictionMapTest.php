<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;

#[CoversClass(ServiceRestrictionMap::class)]
final class ServiceRestrictionMapTest extends TestCase
{
    private ServiceRestrictionMap $map;

    protected function setUp(): void
    {
        $this->map = ServiceRestrictionMap::defaults();
    }

    #[Test]
    public function defaultsContainsRestrictedServices(): void
    {
        self::assertTrue($this->map->isRestricted('Pulsar\Security\Crypto\MasterKey'));
        self::assertTrue($this->map->isRestricted('Pulsar\Security\Crypto\KeyProviderInterface'));
    }

    #[Test]
    public function defaultsContainsSafeServices(): void
    {
        self::assertTrue($this->map->isSafe('Psr\Log\LoggerInterface'));
        self::assertTrue($this->map->isSafe('Psr\EventDispatcher\EventDispatcherInterface'));
    }

    #[Test]
    public function requiredCapabilityReturnsCapabilityForRestrictedService(): void
    {
        $capability = $this->map->requiredCapability('Pulsar\Security\Crypto\MasterKey');

        self::assertSame(ExtensionCapability::CryptoKeyAccess, $capability);
    }

    #[Test]
    public function requiredCapabilityReturnsNullForSafeService(): void
    {
        $capability = $this->map->requiredCapability('Psr\Log\LoggerInterface');

        self::assertNull($capability);
    }

    #[Test]
    public function isUnknownReturnsTrueForUnmappedService(): void
    {
        self::assertTrue($this->map->isUnknown('SomeRandom\Service'));
    }

    #[Test]
    public function isUnknownReturnsFalseForRestrictedService(): void
    {
        self::assertFalse($this->map->isUnknown('Pulsar\Security\Crypto\MasterKey'));
    }

    #[Test]
    public function isUnknownReturnsFalseForSafeService(): void
    {
        self::assertFalse($this->map->isUnknown('Psr\Log\LoggerInterface'));
    }

    #[Test]
    public function customMapWithExplicitServices(): void
    {
        $map = new ServiceRestrictionMap(
            restrictedServices: ['CustomSecret' => ExtensionCapability::CryptoKeyAccess],
            safeServices: ['CustomLogger'],
        );

        self::assertTrue($map->isRestricted('CustomSecret'));
        self::assertTrue($map->isSafe('CustomLogger'));
        self::assertTrue($map->isUnknown('Other'));
    }

    #[Test]
    public function databaseServicesRequireDatabaseRaw(): void
    {
        self::assertSame(
            ExtensionCapability::DatabaseRaw,
            $this->map->requiredCapability('Pulsar\Database\ConnectionInterface'),
        );
    }

    #[Test]
    public function auditSinkRequiresAuditSinkAccess(): void
    {
        self::assertSame(
            ExtensionCapability::AuditSinkAccess,
            $this->map->requiredCapability('Pulsar\Audit\AuditSinkInterface'),
        );
    }

    #[Test]
    public function processExecutionServicesRequireProcessExec(): void
    {
        self::assertSame(
            ExtensionCapability::ProcessExec,
            $this->map->requiredCapability('Pulsar\Process\ProcessManagerInterface'),
        );
    }
}
