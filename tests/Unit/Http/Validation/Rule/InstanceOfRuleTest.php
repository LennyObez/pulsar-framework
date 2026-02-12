<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\InstanceOfRule;
use RuntimeException;
use stdClass;

#[CoversClass(InstanceOfRule::class)]
final class InstanceOfRuleTest extends TestCase
{
    #[Test]
    public function correctInstancePasses(): void
    {
        $rule = new InstanceOfRule(stdClass::class);
        self::assertNull($rule->validate('field', new stdClass(), []));
    }

    #[Test]
    public function wrongInstanceFails(): void
    {
        $rule = new InstanceOfRule(stdClass::class);
        $violation = $rule->validate('field', new RuntimeException(), []);
        self::assertNotNull($violation);
        self::assertSame('instance_of', $violation->rule);
    }

    #[Test]
    public function scalarValueFails(): void
    {
        $rule = new InstanceOfRule(stdClass::class);
        $violation = $rule->validate('field', 'string', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new InstanceOfRule(stdClass::class);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new InstanceOfRule(stdClass::class, message: 'Wrong type.');
        $violation = $rule->validate('field', 'string', []);
        self::assertNotNull($violation);
        self::assertSame('Wrong type.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('instance_of', new InstanceOfRule(stdClass::class)->name());
    }
}
