<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\MinLength;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\StringType;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\Validator;

#[CoversClass(Validator::class)]
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    #[Test]
    public function validDataPasses(): void
    {
        $result = $this->validator->validate(
            ['name' => 'John', 'email' => 'john@example.com'],
            [
                'name' => [new Required(), new StringType()],
                'email' => [new Required(), new StringType()],
            ],
        );

        self::assertTrue($result->passed());
    }

    #[Test]
    public function invalidDataFails(): void
    {
        $result = $this->validator->validate(
            ['name' => '', 'email' => ''],
            [
                'name' => [new Required()],
                'email' => [new Required()],
            ],
        );

        self::assertTrue($result->failed());
        self::assertCount(2, $result->violations);
    }

    #[Test]
    public function requiredShortCircuitsRemainingRules(): void
    {
        $result = $this->validator->validate(
            ['name' => ''],
            [
                'name' => [new Required(), new MinLength(3)],
            ],
        );

        // Only Required violation, MinLength was skipped
        self::assertCount(1, $result->violations);
        self::assertSame('required', $result->violations[0]->rule);
    }

    #[Test]
    public function validateOrFailThrowsOnFailure(): void
    {
        $this->expectException(ValidationException::class);

        $_ = $this->validator->validateOrFail(
            ['name' => ''],
            ['name' => [new Required()]],
        );
    }

    #[Test]
    public function validateOrFailReturnsResultOnSuccess(): void
    {
        $result = $this->validator->validateOrFail(
            ['name' => 'John'],
            ['name' => [new Required()]],
        );

        self::assertTrue($result->passed());
    }

    #[Test]
    public function emptyRulesPass(): void
    {
        $result = $this->validator->validate(
            ['name' => 'John'],
            [],
        );

        self::assertTrue($result->passed());
    }

    #[Test]
    public function emptyDataWithNoRequiredRulesPasses(): void
    {
        $result = $this->validator->validate(
            [],
            ['name' => [new StringType()]],
        );

        // StringType skips null values
        self::assertTrue($result->passed());
    }

    #[Test]
    public function missingFieldWithRequiredRuleFails(): void
    {
        $result = $this->validator->validate(
            [],
            ['name' => [new Required()]],
        );

        self::assertTrue($result->failed());
        self::assertSame('name', $result->violations[0]->field);
    }
}
