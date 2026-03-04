<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\ValidatorBuilder;

final class ValidatorBuilderTest extends TestCase
{
    #[Test]
    public function makeCreatesBuilder(): void
    {
        $builder = ValidatorBuilder::make(['email' => 'test@example.com']);

        self::assertInstanceOf(ValidatorBuilder::class, $builder);
    }

    #[Test]
    public function validatePassesWithValidData(): void
    {
        $result = ValidatorBuilder::make([
            'email' => 'test@example.com',
            'name' => 'Alice',
        ])
            ->rule('email', 'required|email')
            ->rule('name', 'required|string')
            ->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function validateFailsWithMissingRequired(): void
    {
        $result = ValidatorBuilder::make([
            'email' => '',
        ])
            ->rule('email', 'required|email')
            ->validate();

        self::assertTrue($result->failed());
        self::assertNotEmpty($result->forField('email'));
    }

    #[Test]
    public function validateFailsWithInvalidEmail(): void
    {
        $result = ValidatorBuilder::make([
            'email' => 'not-an-email',
        ])
            ->rule('email', 'required|email')
            ->validate();

        self::assertTrue($result->failed());
    }

    #[Test]
    public function rulesMethodAcceptsMultipleFields(): void
    {
        $result = ValidatorBuilder::make([
            'email' => 'test@example.com',
            'name' => 'Alice',
        ])
            ->rules([
                'email' => 'required|email',
                'name' => 'required|string',
            ])
            ->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function validateOrFailThrowsOnInvalidData(): void
    {
        $this->expectException(ValidationException::class);

        (void) ValidatorBuilder::make(['email' => ''])
            ->rule('email', 'required')
            ->validateOrFail();
    }

    #[Test]
    public function validateOrFailReturnsResultOnValidData(): void
    {
        $result = ValidatorBuilder::make(['name' => 'Alice'])
            ->rule('name', 'required|string')
            ->validateOrFail();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function chainMultipleRulesForSameField(): void
    {
        $result = ValidatorBuilder::make(['name' => 'A'])
            ->rule('name', 'required')
            ->rule('name', 'min_length:3')
            ->validate();

        self::assertTrue($result->failed());
    }

    #[Test]
    public function unknownRuleStringFailsWithMessage(): void
    {
        $result = ValidatorBuilder::make(['field' => 'value'])
            ->rule('field', 'nonexistent_rule')
            ->validate();

        self::assertTrue($result->failed());
        $violations = $result->forField('field');
        self::assertStringContainsString('Unknown validation rule', $violations[0]->message);
    }

    #[Test]
    public function ruleAcceptsRuleInterfaceArray(): void
    {
        $result = ValidatorBuilder::make(['email' => 'test@example.com'])
            ->rule('email', [
                new \Pulsar\Http\Validation\Rule\Required(),
                new \Pulsar\Http\Validation\Rule\Email(),
            ])
            ->validate();

        self::assertTrue($result->passed());
    }
}
