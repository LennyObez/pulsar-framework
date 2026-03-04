<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\TrustedFlagger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\Internal\InMemoryTrustedFlaggerRegistry;

#[CoversClass(InMemoryTrustedFlaggerRegistry::class)]
final class TrustedFlaggerRegistryTest extends TestCase
{
    private InMemoryTrustedFlaggerRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryTrustedFlaggerRegistry();
    }

    #[Test]
    public function registerAndIsTrustedReturnsTrue(): void
    {
        $this->registry->register(
            id: 'tf-1',
            name: 'Child Safety Org',
            designatingAuthority: 'Digital Services Coordinator DE',
            expertiseDomain: 'child_safety',
        );

        self::assertTrue($this->registry->isTrusted('tf-1'));
    }

    #[Test]
    public function isTrustedReturnsFalseForUnknownFlagger(): void
    {
        self::assertFalse($this->registry->isTrusted('unknown'));
    }

    #[Test]
    public function revokeRemovesFlagger(): void
    {
        $this->registry->register('tf-2', 'Org A', 'Authority A', 'hate_speech');

        self::assertTrue($this->registry->isTrusted('tf-2'));

        $this->registry->revoke('tf-2');

        self::assertFalse($this->registry->isTrusted('tf-2'));
    }

    #[Test]
    public function revokeNonExistentFlaggerDoesNotThrow(): void
    {
        $this->registry->revoke('nonexistent');

        self::assertSame(0, $this->registry->count());
    }

    #[Test]
    public function allReturnsAllRegisteredFlaggers(): void
    {
        $this->registry->register('tf-1', 'Org A', 'Authority A', 'terrorism');
        $this->registry->register('tf-2', 'Org B', 'Authority B', 'copyright');

        $all = $this->registry->all();

        self::assertCount(2, $all);
        self::assertSame('tf-1', $all[0]['id']);
        self::assertSame('Org A', $all[0]['name']);
        self::assertSame('Authority A', $all[0]['designating_authority']);
        self::assertSame('terrorism', $all[0]['expertise_domain']);
        self::assertSame('tf-2', $all[1]['id']);
    }

    #[Test]
    public function allReturnsEmptyListWhenEmpty(): void
    {
        self::assertSame([], $this->registry->all());
    }

    #[Test]
    public function countReturnsZeroWhenEmpty(): void
    {
        self::assertSame(0, $this->registry->count());
    }

    #[Test]
    public function countReturnsCorrectNumber(): void
    {
        $this->registry->register('tf-1', 'Org A', 'Authority A', 'hate_speech');
        $this->registry->register('tf-2', 'Org B', 'Authority B', 'terrorism');
        $this->registry->register('tf-3', 'Org C', 'Authority C', 'copyright');

        self::assertSame(3, $this->registry->count());
    }

    #[Test]
    public function registerOverwritesExistingFlagger(): void
    {
        $this->registry->register('tf-1', 'Org A', 'Authority A', 'hate_speech');
        $this->registry->register('tf-1', 'Org A Updated', 'Authority A', 'terrorism');

        self::assertSame(1, $this->registry->count());

        $all = $this->registry->all();
        self::assertSame('Org A Updated', $all[0]['name']);
        self::assertSame('terrorism', $all[0]['expertise_domain']);
    }
}
