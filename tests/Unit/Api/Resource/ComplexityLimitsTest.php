<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\ComplexityLimits;

#[CoversClass(ComplexityLimits::class)]
final class ComplexityLimitsTest extends TestCase
{
    #[Test]
    public function defaultLimits(): void
    {
        $limits = new ComplexityLimits();

        self::assertSame(50, $limits->maxFields);
        self::assertSame(3, $limits->maxNestingDepth);
        self::assertSame(10, $limits->maxIncludes);
    }

    #[Test]
    public function validateFieldCountWithinLimit(): void
    {
        $limits = new ComplexityLimits(maxFields: 10);

        // Should not throw
        $limits->validateFieldCount(10);
        $limits->validateFieldCount(5);

        $this->addToAssertionCount(1); // no exception
    }

    #[Test]
    public function validateFieldCountExceedsLimit(): void
    {
        $limits = new ComplexityLimits(maxFields: 10);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Requested 11 fields exceeds maximum of 10/');

        $limits->validateFieldCount(11);
    }

    #[Test]
    public function validateFieldCountWithPerResourceOverride(): void
    {
        $limits = new ComplexityLimits(maxFields: 50);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/exceeds maximum of 5/');

        $limits->validateFieldCount(10, perResourceOverride: 5);
    }

    #[Test]
    public function perResourceOverrideAllowsHigherThanGlobal(): void
    {
        $limits = new ComplexityLimits(maxFields: 10);

        // Override allows 100
        $limits->validateFieldCount(50, perResourceOverride: 100);

        $this->addToAssertionCount(1); // no exception
    }

    #[Test]
    public function validateNestingDepthWithinLimit(): void
    {
        $limits = new ComplexityLimits(maxNestingDepth: 5);

        $limits->validateNestingDepth(5);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateNestingDepthExceedsLimit(): void
    {
        $limits = new ComplexityLimits(maxNestingDepth: 3);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Nesting depth 4 exceeds maximum of 3/');

        $limits->validateNestingDepth(4);
    }

    #[Test]
    public function validateIncludesWithinLimit(): void
    {
        $limits = new ComplexityLimits(maxIncludes: 5);

        $limits->validateIncludes(['comments', 'author', 'tags']);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateIncludesExceedsLimit(): void
    {
        $limits = new ComplexityLimits(maxIncludes: 2);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Requested 3 includes exceeds maximum of 2/');

        $limits->validateIncludes(['comments', 'author', 'tags']);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $limits = ComplexityLimits::fromArray([]);

        self::assertSame(50, $limits->maxFields);
        self::assertSame(3, $limits->maxNestingDepth);
        self::assertSame(10, $limits->maxIncludes);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $limits = ComplexityLimits::fromArray([
            'max_fields' => 100,
            'max_nesting_depth' => 5,
            'max_includes' => 20,
        ]);

        self::assertSame(100, $limits->maxFields);
        self::assertSame(5, $limits->maxNestingDepth);
        self::assertSame(20, $limits->maxIncludes);
    }

    #[Test]
    public function fromArrayIgnoresNonIntegerValues(): void
    {
        $limits = ComplexityLimits::fromArray([
            'max_fields' => 'many',
            'max_nesting_depth' => null,
            'max_includes' => 3.14,
        ]);

        self::assertSame(50, $limits->maxFields);
        self::assertSame(3, $limits->maxNestingDepth);
        self::assertSame(10, $limits->maxIncludes);
    }
}
