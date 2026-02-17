<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\Attribute\ApiField;
use Pulsar\Security\Compliance\DataClassification;

#[CoversClass(ApiField::class)]
final class ApiFieldTest extends TestCase
{
    #[Test]
    public function constructs_with_defaults(): void
    {
        $field = new ApiField();

        self::assertSame(DataClassification::Internal, $field->classification);
        self::assertNull($field->accessLevel);
        self::assertFalse($field->redacted);
        self::assertNull($field->description);
        self::assertNull($field->example);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $field = new ApiField(
            classification: DataClassification::Restricted,
            accessLevel: 'auditor',
            redacted: true,
            description: 'Social security number',
            example: '***-**-1234',
        );

        self::assertSame(DataClassification::Restricted, $field->classification);
        self::assertSame('auditor', $field->accessLevel);
        self::assertTrue($field->redacted);
        self::assertSame('Social security number', $field->description);
        self::assertSame('***-**-1234', $field->example);
    }

    #[Test]
    public function public_classification(): void
    {
        $field = new ApiField(
            classification: DataClassification::Public,
        );

        self::assertSame(DataClassification::Public, $field->classification);
        self::assertFalse($field->redacted);
    }

    #[Test]
    public function confidential_redacted_field(): void
    {
        $field = new ApiField(
            classification: DataClassification::Confidential,
            accessLevel: 'admin',
            redacted: true,
        );

        self::assertSame(DataClassification::Confidential, $field->classification);
        self::assertSame('admin', $field->accessLevel);
        self::assertTrue($field->redacted);
    }
}
