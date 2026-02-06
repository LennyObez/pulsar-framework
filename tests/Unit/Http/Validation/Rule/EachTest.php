<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Each;
use Pulsar\Http\Validation\Rule\Email;

#[CoversClass(Each::class)]
final class EachTest extends TestCase
{
    #[Test]
    public function allElementsPassPasses(): void
    {
        $rule = new Each(new Email());
        self::assertNull($rule->validate('emails', ['a@b.com', 'c@d.com'], []));
    }

    #[Test]
    public function invalidElementFails(): void
    {
        $rule = new Each(new Email());
        $violation = $rule->validate('emails', ['a@b.com', 'invalid'], []);
        self::assertNotNull($violation);
        self::assertSame('emails.1', $violation->field);
        self::assertSame('email', $violation->rule);
    }

    #[Test]
    public function emptyArrayPasses(): void
    {
        $rule = new Each(new Email());
        self::assertNull($rule->validate('emails', [], []));
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $rule = new Each(new Email());
        $violation = $rule->validate('field', 'not-array', []);
        self::assertNotNull($violation);
        self::assertSame('each', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Each(new Email());
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('each', new Each(new Email())->name());
    }

    #[Test]
    public function firstViolationReturned(): void
    {
        $rule = new Each(new Email());
        $violation = $rule->validate('emails', ['bad1', 'bad2'], []);
        self::assertNotNull($violation);
        self::assertSame('emails.0', $violation->field);
    }
}
