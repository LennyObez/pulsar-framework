<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Attribute\Validate;

#[CoversClass(Validate::class)]
final class ValidateAttributeTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $validate = new Validate(
            rule: 'Pulsar\\Http\\Validation\\Rule\\Required',
            parameters: ['message' => 'Name is required'],
            groups: ['create'],
        );

        self::assertSame('Pulsar\\Http\\Validation\\Rule\\Required', $validate->rule);
        self::assertSame(['message' => 'Name is required'], $validate->parameters);
        self::assertSame(['create'], $validate->groups);
    }

    #[Test]
    public function defaultsToEmptyParametersAndGroups(): void
    {
        $validate = new Validate(rule: 'Pulsar\\Http\\Validation\\Rule\\Required');

        self::assertSame([], $validate->parameters);
        self::assertSame([], $validate->groups);
    }
}
