<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Required;

#[CoversClass(Required::class)]
final class RequiredTest extends TestCase
{
    private Required $rule;

    protected function setUp(): void
    {
        $this->rule = new Required();
    }

    #[Test]
    public function nullFails(): void
    {
        $violation = $this->rule->validate('field', null, []);
        self::assertNotNull($violation);
        self::assertSame('required', $violation->rule);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyArrayFails(): void
    {
        $violation = $this->rule->validate('field', [], []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonEmptyStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'hello', []));
    }

    #[Test]
    public function zeroStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '0', []));
    }

    #[Test]
    public function zeroIntPasses(): void
    {
        self::assertNull($this->rule->validate('field', 0, []));
    }

    #[Test]
    public function nonEmptyArrayPasses(): void
    {
        self::assertNull($this->rule->validate('field', ['a'], []));
    }

    #[Test]
    public function customMessageIsUsed(): void
    {
        $rule = new Required('Custom required message');
        $violation = $rule->validate('field', null, []);
        self::assertNotNull($violation);
        self::assertSame('Custom required message', $violation->message);
    }

    #[Test]
    public function nameReturnsRequired(): void
    {
        self::assertSame('required', $this->rule->name());
    }
}
