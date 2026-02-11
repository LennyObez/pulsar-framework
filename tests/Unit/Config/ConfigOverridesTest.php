<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigOverrides;

#[CoversClass(ConfigOverrides::class)]
final class ConfigOverridesTest extends TestCase
{
    #[Test]
    public function hasReturnsFalseForUnregisteredDomain(): void
    {
        $overrides = new ConfigOverrides();

        self::assertFalse($overrides->has('app'));
    }

    #[Test]
    public function hasReturnsTrueAfterAdd(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['debug' => true]);

        self::assertTrue($overrides->has('app'));
    }

    #[Test]
    public function applyReturnsOriginalDataWhenNoDomainOverrides(): void
    {
        $overrides = new ConfigOverrides();
        $data = ['key' => 'value', 'nested' => ['a' => 1]];

        $result = $overrides->apply('app', $data);

        self::assertSame($data, $result);
    }

    #[Test]
    public function applyMergesOverridesIntoData(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['debug' => true]);

        $result = $overrides->apply('app', ['name' => 'Pulsar', 'debug' => false]);

        self::assertSame('Pulsar', $result['name']);
        self::assertTrue($result['debug']);
    }

    #[Test]
    public function applyAddsNewKeysFromOverrides(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['new_key' => 'new_value']);

        $result = $overrides->apply('app', ['existing' => 'original']);

        self::assertSame('original', $result['existing']);
        self::assertSame('new_value', $result['new_key']);
    }

    #[Test]
    public function addMergesMultipleCallsForSameDomain(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['key1' => 'a', 'key2' => 'b']);
        $overrides->add('app', ['key2' => 'c', 'key3' => 'd']);

        $result = $overrides->apply('app', []);

        self::assertSame('a', $result['key1']);
        self::assertSame('c', $result['key2']);
        self::assertSame('d', $result['key3']);
    }

    #[Test]
    public function addWithNestedArrayMergesRecursively(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('db', ['connections' => ['default' => ['host' => 'localhost', 'port' => 3306]]]);
        $overrides->add('db', ['connections' => ['default' => ['port' => 5432]]]);

        $result = $overrides->apply('db', []);

        $connections = $result['connections'];
        assert(is_array($connections));
        $default = $connections['default'];
        assert(is_array($default));
        self::assertSame('localhost', $default['host']);
        self::assertSame(5432, $default['port']);
    }

    #[Test]
    public function applyMergesNestedArraysRecursively(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', [
            'logging' => ['level' => 'debug', 'channels' => ['file' => true]],
        ]);

        $result = $overrides->apply('app', [
            'logging' => ['level' => 'info', 'channels' => ['file' => false, 'syslog' => true]],
        ]);

        $logging = $result['logging'];
        assert(is_array($logging));
        self::assertSame('debug', $logging['level']);
        $channels = $logging['channels'];
        assert(is_array($channels));
        self::assertTrue($channels['file']);
        self::assertTrue($channels['syslog']);
    }

    #[Test]
    public function differentDomainsAreIndependent(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['debug' => true]);
        $overrides->add('cache', ['driver' => 'redis']);

        self::assertTrue($overrides->has('app'));
        self::assertTrue($overrides->has('cache'));
        self::assertFalse($overrides->has('session'));

        $appResult = $overrides->apply('app', ['debug' => false]);
        self::assertTrue($appResult['debug']);
        self::assertArrayNotHasKey('driver', $appResult);

        $cacheResult = $overrides->apply('cache', ['driver' => 'file']);
        self::assertSame('redis', $cacheResult['driver']);
        self::assertArrayNotHasKey('debug', $cacheResult);
    }

    #[Test]
    public function applyDoesNotMutateOriginalData(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['debug' => true]);

        $originalData = ['debug' => false, 'name' => 'Test'];
        $result = $overrides->apply('app', $originalData);

        self::assertNotSame($result['debug'], $originalData['debug']);
        self::assertTrue($result['debug']);
    }

    #[Test]
    public function addWithEmptyArrayDoesNotBreak(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', []);

        self::assertTrue($overrides->has('app'));

        $result = $overrides->apply('app', ['key' => 'value']);
        self::assertSame('value', $result['key']);
    }

    #[Test]
    public function applyWithEmptyBaseData(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['key' => 'value']);

        $result = $overrides->apply('app', []);

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function overridesWinOverBaseDataForScalarValues(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['timeout' => 60]);

        $result = $overrides->apply('app', ['timeout' => 30]);

        self::assertSame(60, $result['timeout']);
    }

    #[Test]
    public function addSecondCallOverridesPreservesPreviousUntouchedKeys(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['a' => 1, 'b' => 2]);
        $overrides->add('app', ['b' => 3]);

        $result = $overrides->apply('app', []);

        self::assertSame(1, $result['a']);
        self::assertSame(3, $result['b']);
    }

    #[Test]
    public function multipleDomainsCanBeRegisteredAndQueried(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['name' => 'App1']);
        $overrides->add('cache', ['driver' => 'redis']);
        $overrides->add('session', ['lifetime' => 120]);

        self::assertTrue($overrides->has('app'));
        self::assertTrue($overrides->has('cache'));
        self::assertTrue($overrides->has('session'));
        self::assertFalse($overrides->has('database'));
    }

    #[Test]
    public function applyOverridesCanReplaceNestedArrayWithScalar(): void
    {
        $overrides = new ConfigOverrides();
        $overrides->add('app', ['logging' => 'disabled']);

        $result = $overrides->apply('app', ['logging' => ['level' => 'debug']]);

        self::assertSame('disabled', $result['logging']);
    }
}
