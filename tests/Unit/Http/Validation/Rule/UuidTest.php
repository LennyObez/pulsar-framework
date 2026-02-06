<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Uuid;

#[CoversClass(Uuid::class)]
final class UuidTest extends TestCase
{
    private Uuid $rule;

    protected function setUp(): void
    {
        $this->rule = new Uuid();
    }

    #[Test]
    public function validUuidV4Passes(): void
    {
        self::assertNull($this->rule->validate('field', '550e8400-e29b-41d4-a716-446655440000', []));
    }

    #[Test]
    public function validUuidV1Passes(): void
    {
        self::assertNull($this->rule->validate('field', '6ba7b810-9dad-11d1-80b4-00c04fd430c8', []));
    }

    #[Test]
    public function uppercaseUuidPasses(): void
    {
        self::assertNull($this->rule->validate('field', '550E8400-E29B-41D4-A716-446655440000', []));
    }

    #[Test]
    public function invalidUuidFails(): void
    {
        $violation = $this->rule->validate('field', 'not-a-uuid', []);
        self::assertNotNull($violation);
        self::assertSame('uuid', $violation->rule);
    }

    #[Test]
    public function uuidWithoutHyphensFails(): void
    {
        $violation = $this->rule->validate('field', '550e8400e29b41d4a716446655440000', []);
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
        $rule = new Uuid(message: 'Bad UUID');
        $violation = $rule->validate('field', 'invalid', []);
        self::assertNotNull($violation);
        self::assertSame('Bad UUID', $violation->message);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $violation = $this->rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
