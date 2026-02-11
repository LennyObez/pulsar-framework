<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\StaticConfigCenter;

#[CoversClass(StaticConfigCenter::class)]
final class StaticConfigCenterTest extends TestCase
{
    #[Test]
    public function getAndSet(): void
    {
        $center = new StaticConfigCenter();
        $center->set('db', 'host', 'localhost');

        self::assertSame('localhost', $center->get('db', 'host'));
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $center = new StaticConfigCenter();

        self::assertNull($center->get('db', 'host'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $center = new StaticConfigCenter(['db' => ['host' => 'localhost']]);

        self::assertTrue($center->has('db', 'host'));
        self::assertFalse($center->has('db', 'port'));
        self::assertFalse($center->has('cache', 'host'));
    }

    #[Test]
    public function deleteRemovesKey(): void
    {
        $center = new StaticConfigCenter(['db' => ['host' => 'localhost', 'port' => '5432']]);

        self::assertTrue($center->delete('db', 'host'));
        self::assertNull($center->get('db', 'host'));
        self::assertSame('5432', $center->get('db', 'port'));
    }

    #[Test]
    public function deleteReturnsFalseForMissingKey(): void
    {
        $center = new StaticConfigCenter();

        self::assertFalse($center->delete('db', 'host'));
    }

    #[Test]
    public function deleteRemovesNamespaceWhenEmpty(): void
    {
        $center = new StaticConfigCenter(['db' => ['host' => 'localhost']]);

        $center->delete('db', 'host');

        self::assertNotContains('db', $center->namespaces());
    }

    #[Test]
    public function allReturnsNamespaceEntries(): void
    {
        $center = new StaticConfigCenter(['db' => ['host' => 'localhost', 'port' => '5432']]);

        self::assertSame(['host' => 'localhost', 'port' => '5432'], $center->all('db'));
    }

    #[Test]
    public function allReturnsEmptyForMissingNamespace(): void
    {
        $center = new StaticConfigCenter();

        self::assertSame([], $center->all('unknown'));
    }

    #[Test]
    public function namespacesListsAll(): void
    {
        $center = new StaticConfigCenter([
            'db' => ['host' => 'localhost'],
            'cache' => ['host' => 'redis'],
        ]);

        $namespaces = $center->namespaces();
        self::assertContains('db', $namespaces);
        self::assertContains('cache', $namespaces);
    }

    #[Test]
    public function fromArrayNormalizesData(): void
    {
        $center = StaticConfigCenter::fromArray([
            'db' => ['host' => 'localhost', 'port' => '5432'],
            'invalid' => 'not_array',
        ]);

        self::assertSame('localhost', $center->get('db', 'host'));
        self::assertSame([], $center->all('invalid'));
    }
}
