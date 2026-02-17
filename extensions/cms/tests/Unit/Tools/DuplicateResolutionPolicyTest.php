<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\DuplicateResolutionPolicy;

#[CoversClass(DuplicateResolutionPolicy::class)]
final class DuplicateResolutionPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('policyProvider')]
    public function fromValueResolves(string $value, DuplicateResolutionPolicy $expected): void
    {
        self::assertSame($expected, DuplicateResolutionPolicy::from($value));
    }

    /**
     * @return array<string, array{string, DuplicateResolutionPolicy}>
     */
    public static function policyProvider(): array
    {
        return [
            'replace' => ['replace', DuplicateResolutionPolicy::Replace],
            'import_as_new' => ['import_as_new', DuplicateResolutionPolicy::ImportAsNew],
            'skip' => ['skip', DuplicateResolutionPolicy::Skip],
            'merge' => ['merge', DuplicateResolutionPolicy::Merge],
        ];
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(DuplicateResolutionPolicy::tryFrom('delete'));
    }
}
