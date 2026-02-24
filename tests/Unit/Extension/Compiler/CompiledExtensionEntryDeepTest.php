<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Compiler\CompiledExtensionEntry;

#[CoversClass(CompiledExtensionEntry::class)]
final class CompiledExtensionEntryDeepTest extends TestCase
{
    #[Test]
    public function fromArrayWithNonStringNameDefaultsToEmpty(): void
    {
        $entry = CompiledExtensionEntry::fromArray(['name' => 123]);

        self::assertSame('', $entry->name);
    }

    #[Test]
    public function fromArrayWithNonStringVersionDefaultsToEmpty(): void
    {
        $entry = CompiledExtensionEntry::fromArray(['version' => false]);

        self::assertSame('', $entry->version);
    }

    #[Test]
    public function fromArrayWithNonStringExtensionClassDefaultsToEmpty(): void
    {
        $entry = CompiledExtensionEntry::fromArray(['extensionClass' => []]);

        self::assertSame('', $entry->extensionClass);
    }

    #[Test]
    public function fromArrayWithNonStringTrustTierDefaultsToCommunity(): void
    {
        $entry = CompiledExtensionEntry::fromArray(['trustTier' => 42]);

        self::assertSame('community', $entry->trustTier);
    }

    #[Test]
    public function fromArrayWithMissingEnabledDefaultsToTrue(): void
    {
        $entry = CompiledExtensionEntry::fromArray([]);

        self::assertTrue($entry->enabled);
    }

    #[Test]
    public function fromArrayWithEnabledFalse(): void
    {
        $entry = CompiledExtensionEntry::fromArray(['enabled' => false]);

        self::assertFalse($entry->enabled);
    }

    #[Test]
    public function fromArrayWithMissingDependenciesDefaultsToEmpty(): void
    {
        $entry = CompiledExtensionEntry::fromArray([]);

        self::assertSame([], $entry->dependencies);
    }

    #[Test]
    public function fromArrayWithMissingNameDefaultsToEmpty(): void
    {
        $entry = CompiledExtensionEntry::fromArray([]);

        self::assertSame('', $entry->name);
    }

    #[Test]
    public function toArrayRoundTrip(): void
    {
        $entry = new CompiledExtensionEntry(
            name: 'test/ext',
            version: '1.2.3',
            extensionClass: 'Test\\Ext',
            enabled: true,
            dependencies: ['other/dep'],
            trustTier: 'verified',
        );

        $array = $entry->toArray();

        self::assertSame('test/ext', $array['name']);
        self::assertSame('1.2.3', $array['version']);
        self::assertSame('Test\\Ext', $array['extensionClass']);
        self::assertTrue($array['enabled']);
        self::assertSame(['other/dep'], $array['dependencies']);
        self::assertSame('verified', $array['trustTier']);
    }
}
