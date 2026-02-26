<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\EndsWith;

#[CoversClass(EndsWith::class)]
final class EndsWithTest extends TestCase
{
    #[Test]
    public function stringEndingWithSuffixPasses(): void
    {
        $rule = new EndsWith('.php');
        self::assertNull($rule->validate('field', 'index.php', []));
    }

    #[Test]
    public function stringNotEndingWithSuffixFails(): void
    {
        $rule = new EndsWith('.php');
        $violation = $rule->validate('field', 'index.html', []);
        self::assertNotNull($violation);
        self::assertSame('ends_with', $violation->rule);
    }

    #[Test]
    public function exactMatchPasses(): void
    {
        $rule = new EndsWith('world');
        self::assertNull($rule->validate('field', 'world', []));
    }

    #[Test]
    public function caseSensitive(): void
    {
        $rule = new EndsWith('.PHP');
        $violation = $rule->validate('field', 'index.php', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptySuffixAlwaysPasses(): void
    {
        $rule = new EndsWith('');
        self::assertNull($rule->validate('field', 'anything', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new EndsWith('.php');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new EndsWith('.php', message: 'Must be PHP');
        $violation = $rule->validate('field', 'file.js', []);
        self::assertNotNull($violation);
        self::assertSame('Must be PHP', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new EndsWith('5');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
