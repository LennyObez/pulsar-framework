<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Json;

#[CoversClass(Json::class)]
final class JsonTest extends TestCase
{
    private Json $rule;

    protected function setUp(): void
    {
        $this->rule = new Json();
    }

    #[Test]
    public function validJsonObjectPasses(): void
    {
        self::assertNull($this->rule->validate('field', '{"key":"value"}', []));
    }

    #[Test]
    public function validJsonArrayPasses(): void
    {
        self::assertNull($this->rule->validate('field', '[1,2,3]', []));
    }

    #[Test]
    public function validJsonStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '"hello"', []));
    }

    #[Test]
    public function validJsonNumberPasses(): void
    {
        self::assertNull($this->rule->validate('field', '42', []));
    }

    #[Test]
    public function invalidJsonFails(): void
    {
        $violation = $this->rule->validate('field', '{invalid}', []);
        self::assertNotNull($violation);
        self::assertSame('json', $violation->rule);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Json(message: 'Not JSON');
        $violation = $rule->validate('field', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Not JSON', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $violation = $this->rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
