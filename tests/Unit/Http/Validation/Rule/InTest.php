<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\In;

#[CoversClass(In::class)]
final class InTest extends TestCase
{
    /**
     * @return iterable<string, array{list<mixed>, mixed}>
     */
    public static function allowedValueProvider(): iterable
    {
        yield 'exact string match' => [['active', 'inactive', 'pending'], 'active'];
        yield 'last in list' => [['active', 'inactive', 'pending'], 'pending'];
        yield 'numeric string matches int' => [[1, 2, 3], '1'];
        yield 'integer in list' => [[1, 2, 3], 2];
        yield 'single-item list match' => [['only'], 'only'];
    }

    /**
     * @param list<mixed> $allowed
     */
    #[Test]
    #[DataProvider('allowedValueProvider')]
    public function allowedValuePasses(array $allowed, mixed $value): void
    {
        $rule = new In($allowed);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{list<mixed>, mixed}>
     */
    public static function disallowedValueProvider(): iterable
    {
        yield 'not in list' => [['active', 'inactive'], 'deleted'];
        yield 'case mismatch' => [['active'], 'Active'];
        yield 'trimmed vs untrimmed' => [['active'], ' active'];
        yield 'empty list' => [[], 'anything'];
        yield 'numeric mismatch' => [[1, 2, 3], 4];
    }

    /**
     * @param list<mixed> $allowed
     */
    #[Test]
    #[DataProvider('disallowedValueProvider')]
    public function disallowedValueFails(array $allowed, mixed $value): void
    {
        $rule = new In($allowed);
        $violation = $rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('in', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new In(['a', 'b']);
        self::assertNull($rule->validate('field', null, []));
    }
}
