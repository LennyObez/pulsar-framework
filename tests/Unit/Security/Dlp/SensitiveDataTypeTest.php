<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\SensitiveDataType;

use function count;

#[CoversNothing]
final class SensitiveDataTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveUniqueValues(): void
    {
        $values = [];

        foreach (SensitiveDataType::cases() as $type) {
            self::assertNotContains($type->value, $values, "Duplicate value: {$type->value}");
            $values[] = $type->value;
        }
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (SensitiveDataType::cases() as $type) {
            self::assertSame($type, SensitiveDataType::from($type->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(SensitiveDataType::tryFrom('not_a_real_type'));
    }

    #[Test]
    public function caseCountIsAtLeastFive(): void
    {
        // DLP should recognize at least: credit card, SSN, email, phone, API key
        self::assertGreaterThanOrEqual(5, count(SensitiveDataType::cases()));
    }
}
