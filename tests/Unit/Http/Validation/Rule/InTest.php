<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\In;

#[CoversClass(In::class)]
final class InTest extends TestCase
{
    #[Test]
    public function allowedValuePasses(): void
    {
        $rule = new In(['active', 'inactive', 'pending']);
        self::assertNull($rule->validate('field', 'active', []));
    }

    #[Test]
    public function disallowedValueFails(): void
    {
        $rule = new In(['active', 'inactive']);
        $violation = $rule->validate('field', 'deleted', []);
        self::assertNotNull($violation);
        self::assertSame('in', $violation->rule);
    }

    #[Test]
    public function looseComparisonAllowsStringToIntMatch(): void
    {
        // HTTP inputs are strings, so '1' should match int 1
        $rule = new In([1, 2, 3]);
        self::assertNull($rule->validate('field', '1', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new In(['a', 'b']);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function emptyAllowedListFails(): void
    {
        $rule = new In([]);
        $violation = $rule->validate('field', 'anything', []);
        self::assertNotNull($violation);
    }
}
