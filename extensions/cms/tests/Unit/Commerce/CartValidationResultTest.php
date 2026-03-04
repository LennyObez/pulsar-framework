<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CartValidationResult;

#[CoversClass(CartValidationResult::class)]
final class CartValidationResultTest extends TestCase
{
    #[Test]
    public function validCartResult(): void
    {
        $result = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => 'p-1', 'quantity' => 2, 'unitPrice' => 1999, 'currency' => 'EUR'],
            ],
        );

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->validatedItems);
        self::assertSame('p-1', $result->validatedItems[0]['productId']);
    }

    #[Test]
    public function invalidCartResultHasErrors(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: ['Product not available', 'Price changed'],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertCount(2, $result->errors);
    }
}
