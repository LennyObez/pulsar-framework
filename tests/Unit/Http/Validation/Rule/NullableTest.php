<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Nullable;

#[CoversClass(Nullable::class)]
final class NullableTest extends TestCase
{
    private Nullable $rule;

    protected function setUp(): void
    {
        $this->rule = new Nullable();
    }

    #[Test]
    public function nullPasses(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function nonNullPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'value', []));
    }

    #[Test]
    public function emptyStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '', []));
    }

    #[Test]
    public function zeroPasses(): void
    {
        self::assertNull($this->rule->validate('field', 0, []));
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('nullable', $this->rule->name());
    }
}
