<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\TranslatableViolation;
use Pulsar\Http\Validation\Violation;
use Pulsar\I18n\TranslatorInterface;

#[CoversClass(TranslatableViolation::class)]
final class TranslatableViolationTest extends TestCase
{
    #[Test]
    public function extendsViolation(): void
    {
        $violation = new TranslatableViolation(
            field: 'email',
            rule: 'required',
            translationKey: 'required',
        );

        self::assertInstanceOf(Violation::class, $violation);
    }

    #[Test]
    public function carriesTranslationKeyAndParameters(): void
    {
        $violation = new TranslatableViolation(
            field: 'email',
            rule: 'min',
            translationKey: 'min',
            parameters: ['field' => 'email', 'min' => '3'],
        );

        self::assertSame('min', $violation->translationKey);
        self::assertSame(['field' => 'email', 'min' => '3'], $violation->parameters);
        self::assertSame('email', $violation->field);
        self::assertSame('min', $violation->rule);
        self::assertSame('min', $violation->message);
    }

    #[Test]
    public function translateResolvesViaTranslator(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('translate')->willReturn('The email field must be at least 3.');

        $violation = new TranslatableViolation(
            field: 'email',
            rule: 'min',
            translationKey: 'min',
            parameters: ['field' => 'email', 'min' => '3'],
        );

        $result = $violation->translate($translator);

        self::assertSame('The email field must be at least 3.', $result);
    }

    #[Test]
    public function toArrayReturnsParentFormat(): void
    {
        $violation = new TranslatableViolation(
            field: 'name',
            rule: 'required',
            translationKey: 'required',
        );

        $array = $violation->toArray();

        self::assertSame('name', $array['field']);
        self::assertSame('required', $array['message']);
        self::assertSame('required', $array['rule']);
        self::assertSame('VALIDATION_REQUIRED', $array['code']);
    }

    #[Test]
    public function explicitCodePassedToParent(): void
    {
        $violation = new TranslatableViolation(
            field: 'email',
            rule: 'email',
            translationKey: 'email',
            code: 'CUSTOM_CODE',
        );

        self::assertSame('CUSTOM_CODE', $violation->code);
    }
}
