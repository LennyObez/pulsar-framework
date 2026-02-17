<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;

#[CoversClass(CssValidationResult::class)]
final class CssValidationResultTest extends TestCase
{
    #[Test]
    public function valid_result_has_no_errors(): void
    {
        $result = new CssValidationResult(
            isValid: true,
            errors: [],
            sanitizedCss: 'body { color: red; }',
        );

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertSame('body { color: red; }', $result->sanitizedCss);
    }

    #[Test]
    public function invalid_result_contains_error_messages(): void
    {
        $result = new CssValidationResult(
            isValid: false,
            errors: ['@import is not allowed', 'expression() is not allowed'],
            sanitizedCss: 'body { }',
        );

        self::assertFalse($result->isValid);
        self::assertCount(2, $result->errors);
        self::assertSame('@import is not allowed', $result->errors[0]);
    }
}
