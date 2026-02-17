<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexJustification;
use ValueError;

use function sprintf;

#[CoversClass(VexJustification::class)]
final class VexJustificationTest extends TestCase
{
    #[Test]
    public function enumHasExactlyFourCases(): void
    {
        self::assertCount(4, VexJustification::cases());
    }

    #[Test]
    #[DataProvider('caseValueProvider')]
    public function eachCaseHasExpectedBackingValue(VexJustification $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{VexJustification, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'ComponentNotPresent' => [VexJustification::ComponentNotPresent, 'component_not_present'];
        yield 'VulnerableCodeNotPresent' => [VexJustification::VulnerableCodeNotPresent, 'vulnerable_code_not_present'];
        yield 'VulnerableCodeNotInExecutePath' => [VexJustification::VulnerableCodeNotInExecutePath, 'vulnerable_code_not_in_execute_path'];
        yield 'InlineMitigationsAlreadyExist' => [VexJustification::InlineMitigationsAlreadyExist, 'inline_mitigations_already_exist'];
    }

    #[Test]
    #[DataProvider('caseValueProvider')]
    public function fromReturnsCorrectCaseForValidValue(VexJustification $expectedCase, string $value): void
    {
        self::assertSame($expectedCase, VexJustification::from($value));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(VexJustification::tryFrom('nonexistent'));
    }

    #[Test]
    public function tryFromReturnsNullForEmptyString(): void
    {
        self::assertNull(VexJustification::tryFrom(''));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        VexJustification::from('not_a_justification');
    }

    #[Test]
    public function casesAreDistinct(): void
    {
        $values = array_map(
            static fn(VexJustification $case): string => $case->value,
            VexJustification::cases(),
        );

        self::assertSame($values, array_unique($values));
    }

    #[Test]
    public function backingValuesUseSnakeCaseConvention(): void
    {
        foreach (VexJustification::cases() as $case) {
            self::assertMatchesRegularExpression(
                '/^[a-z]+(_[a-z]+)*$/',
                $case->value,
                sprintf('Case %s backing value should use snake_case', $case->name),
            );
        }
    }

    #[Test]
    public function allCasesAlignWithOpenVexSpecificationVocabulary(): void
    {
        $openVexJustifications = [
            'component_not_present',
            'vulnerable_code_not_present',
            'vulnerable_code_not_in_execute_path',
            'inline_mitigations_already_exist',
        ];

        $actualValues = array_map(
            static fn(VexJustification $case): string => $case->value,
            VexJustification::cases(),
        );

        self::assertSame($openVexJustifications, $actualValues);
    }
}
