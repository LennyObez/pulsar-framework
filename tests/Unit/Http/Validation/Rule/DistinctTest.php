<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Distinct;

#[CoversClass(Distinct::class)]
final class DistinctTest extends TestCase
{
    private Distinct $rule;

    protected function setUp(): void
    {
        $this->rule = new Distinct();
    }

    #[Test]
    public function uniqueElementsPasses(): void
    {
        self::assertNull($this->rule->validate('field', [1, 2, 3], []));
    }

    #[Test]
    public function duplicateElementsFails(): void
    {
        $violation = $this->rule->validate('field', [1, 2, 2], []);
        self::assertNotNull($violation);
        self::assertSame('distinct', $violation->rule);
    }

    #[Test]
    public function strictComparison(): void
    {
        self::assertNull($this->rule->validate('field', [1, '1', true], []));
    }

    #[Test]
    public function emptyArrayPasses(): void
    {
        self::assertNull($this->rule->validate('field', [], []));
    }

    #[Test]
    public function singleElementPasses(): void
    {
        self::assertNull($this->rule->validate('field', ['only'], []));
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $violation = $this->rule->validate('field', 'string', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Distinct(message: 'No dupes.');
        $violation = $rule->validate('field', [1, 1], []);
        self::assertNotNull($violation);
        self::assertSame('No dupes.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('distinct', $this->rule->name());
    }
}
