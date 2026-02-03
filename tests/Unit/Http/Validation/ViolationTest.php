<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Violation;

#[CoversClass(Violation::class)]
final class ViolationTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $violation = new Violation(
            field: 'email',
            message: 'The email field is required.',
            rule: 'required',
        );

        self::assertSame('email', $violation->field);
        self::assertSame('The email field is required.', $violation->message);
        self::assertSame('required', $violation->rule);
    }

    #[Test]
    public function toArrayReturnsStructuredArray(): void
    {
        $violation = new Violation(
            field: 'name',
            message: 'The name field must be a string.',
            rule: 'string',
        );

        self::assertSame([
            'field' => 'name',
            'message' => 'The name field must be a string.',
            'rule' => 'string',
        ], $violation->toArray());
    }
}
