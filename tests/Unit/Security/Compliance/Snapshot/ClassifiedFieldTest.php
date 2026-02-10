<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Snapshot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Compliance\Snapshot\ClassifiedField;

#[CoversClass(ClassifiedField::class)]
final class ClassifiedFieldTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $field = new ClassifiedField(
            name: 'account_number',
            value: '1234-5678',
            classification: DataClassification::Confidential,
        );

        self::assertSame('account_number', $field->name);
        self::assertSame('1234-5678', $field->value);
        self::assertSame(DataClassification::Confidential, $field->classification);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $field = new ClassifiedField(
            name: 'ssn',
            value: '***-**-1234',
            classification: DataClassification::Restricted,
        );

        $array = $field->toArray();

        self::assertSame('ssn', $array['name']);
        self::assertSame('***-**-1234', $array['value']);
        self::assertSame('restricted', $array['classification']);
    }

    #[Test]
    public function toArrayContainsAllKeys(): void
    {
        $field = new ClassifiedField(
            name: 'email',
            value: 'user@example.com',
            classification: DataClassification::Internal,
        );

        $array = $field->toArray();

        self::assertArrayHasKey('name', $array);
        self::assertArrayHasKey('value', $array);
        self::assertArrayHasKey('classification', $array);
        self::assertCount(3, $array);
    }

    #[Test]
    public function toArraySerializesClassificationAsString(): void
    {
        $field = new ClassifiedField(
            name: 'status',
            value: 'active',
            classification: DataClassification::Public,
        );

        $array = $field->toArray();

        self::assertSame('public', $array['classification']);
    }

    #[Test]
    public function acceptsMixedValueTypes(): void
    {
        $intField = new ClassifiedField(
            name: 'balance',
            value: 50000,
            classification: DataClassification::Confidential,
        );

        $nullField = new ClassifiedField(
            name: 'notes',
            value: null,
            classification: DataClassification::Internal,
        );

        self::assertSame(50000, $intField->value);
        self::assertNull($nullField->value);
        self::assertSame(50000, $intField->toArray()['value']);
        self::assertNull($nullField->toArray()['value']);
    }
}
