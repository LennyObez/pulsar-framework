<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\CompiledTemplate;
use ReflectionClass;

#[CoversClass(CompiledTemplate::class)]
final class CompiledTemplateTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $compiled = new CompiledTemplate(
            compiledPath: '/cache/view_abc123.php',
            sourceHash: 'abc123def456',
            compiledAt: 1700000000,
        );

        self::assertSame('/cache/view_abc123.php', $compiled->compiledPath);
        self::assertSame('abc123def456', $compiled->sourceHash);
        self::assertSame(1700000000, $compiled->compiledAt);
    }

    #[Test]
    public function isValidForReturnsTrueWhenHashMatches(): void
    {
        $compiled = new CompiledTemplate(
            compiledPath: '/cache/view.php',
            sourceHash: 'abc123',
            compiledAt: 1700000000,
        );

        self::assertTrue($compiled->isValidFor('abc123'));
    }

    #[Test]
    public function isValidForReturnsFalseWhenHashDiffers(): void
    {
        $compiled = new CompiledTemplate(
            compiledPath: '/cache/view.php',
            sourceHash: 'abc123',
            compiledAt: 1700000000,
        );

        self::assertFalse($compiled->isValidFor('def456'));
    }

    #[Test]
    public function isReadonly(): void
    {
        $compiled = new CompiledTemplate(
            compiledPath: '/cache/view.php',
            sourceHash: 'abc',
            compiledAt: 0,
        );

        $reflection = new ReflectionClass($compiled);

        self::assertTrue($reflection->isReadOnly());
    }
}
