<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\StartsWith;

#[CoversClass(StartsWith::class)]
final class StartsWithTest extends TestCase
{
    #[Test]
    public function stringStartingWithPrefixPasses(): void
    {
        $rule = new StartsWith('http');
        self::assertNull($rule->validate('field', 'https://example.com', []));
    }

    #[Test]
    public function stringNotStartingWithPrefixFails(): void
    {
        $rule = new StartsWith('http');
        $violation = $rule->validate('field', 'ftp://example.com', []);
        self::assertNotNull($violation);
        self::assertSame('starts_with', $violation->rule);
    }

    #[Test]
    public function exactMatchPasses(): void
    {
        $rule = new StartsWith('hello');
        self::assertNull($rule->validate('field', 'hello', []));
    }

    #[Test]
    public function caseSensitive(): void
    {
        $rule = new StartsWith('Hello');
        $violation = $rule->validate('field', 'hello world', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyPrefixAlwaysPasses(): void
    {
        $rule = new StartsWith('');
        self::assertNull($rule->validate('field', 'anything', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new StartsWith('pre');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new StartsWith('pre', message: 'Must start with pre');
        $violation = $rule->validate('field', 'post', []);
        self::assertNotNull($violation);
        self::assertSame('Must start with pre', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new StartsWith('1');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
