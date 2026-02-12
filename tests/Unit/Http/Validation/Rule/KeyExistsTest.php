<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\KeyExists;

#[CoversClass(KeyExists::class)]
final class KeyExistsTest extends TestCase
{
    #[Test]
    public function allKeysExistPasses(): void
    {
        $rule = new KeyExists('name', 'email');
        self::assertNull($rule->validate('field', ['name' => 'John', 'email' => 'j@e.com'], []));
    }

    #[Test]
    public function missingKeyFails(): void
    {
        $rule = new KeyExists('name', 'email');
        $violation = $rule->validate('field', ['name' => 'John'], []);
        self::assertNotNull($violation);
        self::assertSame('key_exists', $violation->rule);
    }

    #[Test]
    public function emptyArrayWithKeysFails(): void
    {
        $rule = new KeyExists('name');
        $violation = $rule->validate('field', [], []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function keyWithNullValuePasses(): void
    {
        $rule = new KeyExists('name');
        self::assertNull($rule->validate('field', ['name' => null], []));
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $rule = new KeyExists('name');
        $violation = $rule->validate('field', 'string', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new KeyExists('name');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('key_exists', new KeyExists('a')->name());
    }
}
