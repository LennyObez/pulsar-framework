<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Group;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Group\ValidationGroup;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\StringType;

#[CoversClass(ValidationGroup::class)]
final class ValidationGroupTest extends TestCase
{
    #[Test]
    public function constructsWithNameAndRules(): void
    {
        $rules = ['name' => [new Required()]];
        $group = new ValidationGroup('create', $rules);

        self::assertSame('create', $group->name);
        self::assertCount(1, $group->rules);
    }

    #[Test]
    public function constructsWithEmptyRules(): void
    {
        $group = new ValidationGroup('update');

        self::assertSame('update', $group->name);
        self::assertSame([], $group->rules);
    }

    #[Test]
    public function withFieldReturnsNewInstance(): void
    {
        $group = new ValidationGroup('create');
        $updated = $group->withField('name', [new Required()]);

        self::assertNotSame($group, $updated);
        self::assertSame([], $group->rules);
        self::assertCount(1, $updated->rules);
    }

    #[Test]
    public function withFieldPreservesExistingRules(): void
    {
        $group = new ValidationGroup('create', [
            'name' => [new Required()],
        ]);
        $updated = $group->withField('email', [new Required(), new StringType()]);

        self::assertCount(2, $updated->rules);
        self::assertTrue($updated->hasField('name'));
        self::assertTrue($updated->hasField('email'));
    }

    #[Test]
    public function hasFieldDetectsPresence(): void
    {
        $group = new ValidationGroup('create', [
            'name' => [new Required()],
        ]);

        self::assertTrue($group->hasField('name'));
        self::assertFalse($group->hasField('email'));
    }

    #[Test]
    public function fieldsReturnsFieldNames(): void
    {
        $group = new ValidationGroup('create', [
            'name' => [new Required()],
            'email' => [new Required()],
        ]);

        self::assertSame(['name', 'email'], $group->fields());
    }

    #[Test]
    public function emptyGroupHasNoFields(): void
    {
        $group = new ValidationGroup('empty');

        self::assertSame([], $group->fields());
    }
}
