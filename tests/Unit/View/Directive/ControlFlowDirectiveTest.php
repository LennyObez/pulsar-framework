<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\ControlFlowDirective;

#[CoversClass(ControlFlowDirective::class)]
final class ControlFlowDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsConfiguredName(): void
    {
        $directive = new ControlFlowDirective('if', 'if');

        self::assertSame('if', $directive->name());
    }

    #[Test]
    #[DataProvider('controlFlowProvider')]
    public function compileProducesCorrectPhp(string $directiveName, string $phpKeyword, string $expression, string $expected): void
    {
        $directive = new ControlFlowDirective($directiveName, $phpKeyword);

        self::assertSame($expected, $directive->compile($expression));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function controlFlowProvider(): iterable
    {
        yield 'if' => ['if', 'if', '$x > 0', '<?php if ($x > 0): ?>'];
        yield 'elseif' => ['elseif', 'elseif', '$x === 1', '<?php elseif ($x === 1): ?>'];
        yield 'foreach' => ['foreach', 'foreach', '$items as $item', '<?php foreach ($items as $item): ?>'];
        yield 'for' => ['for', 'for', '$i = 0; $i < 10; $i++', '<?php for ($i = 0; $i < 10; $i++): ?>'];
        yield 'while' => ['while', 'while', '$running', '<?php while ($running): ?>'];
        yield 'switch' => ['switch', 'switch', '$action', '<?php switch ($action): ?>'];
    }
}
