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
    public function previouslyUnmappedRulesAreParsedAndEnforced(): void
    {
        // Every shipped rule name resolves to its real implementation, with its
        // DSL arguments parsed. A name missing from the mapping table falls
        // through to CustomStringRule and always fails with "Unknown validation
        // rule" — so a gap in the table reads as a rejection of valid input.
        $valid = ValidatorBuilder::make([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'flag' => true,
            'color' => 'green',
            'count' => 7,
        ])
            ->rule('id', 'uuid')
            ->rule('flag', 'boolean')
            ->rule('color', 'in:red,green,blue')
            ->rule('count', 'between:1,10')
            ->validate();

        self::assertTrue($valid->passed());
    }

    #[Test]
    public function mappedRuleRejectsInvalidValueWithItsOwnMessage(): void
    {
        // The counterpart assertion: a mapped rule enforces its constraint and
        // reports its own message. Asserting only that validation failed would
        // pass just as well on the generic unknown-rule message.
        $result = ValidatorBuilder::make(['color' => 'purple'])
            ->rule('color', 'in:red,green,blue')
            ->validate();

        self::assertTrue($result->failed());
        self::assertStringNotContainsString('Unknown validation rule', $result->forField('color')[0]->message);
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
