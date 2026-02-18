<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CartValidationResult;

#[CoversClass(CartValidationResult::class)]
final class CheckoutValidatorTest extends TestCase
{
    // ── Valid cart ───────────────────────────────────────────────────

    #[Test]
    public function test_valid_cart_result(): void
    {
        $result = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => 'p1', 'quantity' => 2, 'unitPrice' => 1000, 'currency' => 'EUR'],
                ['productId' => 'p2', 'quantity' => 1, 'unitPrice' => 2500, 'currency' => 'EUR'],
            ],
        );

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertCount(2, $result->validatedItems);
    }

    // ── Product not found ───────────────────────────────────────────

    #[Test]
    public function test_product_not_found_error(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: ['Product not found: p-nonexistent'],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertContains('Product not found: p-nonexistent', $result->errors);
    }

    // ── Product not active ──────────────────────────────────────────

    #[Test]
    public function test_product_not_active_error(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: ['Product is not active: p-archived'],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertContains('Product is not active: p-archived', $result->errors);
    }

    // ── Insufficient stock ──────────────────────────────────────────

    #[Test]
    public function test_insufficient_stock_error(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: ['Insufficient stock for product p1: requested 10, available 3'],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('Insufficient stock', $result->errors[0]);
    }

    // ── Price changed ───────────────────────────────────────────────

    #[Test]
    public function test_price_changed_error(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: ['Price changed for product p1: expected 1000, actual 1200'],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertStringContainsString('Price changed', $result->errors[0]);
    }

    // ── Multiple validation errors ──────────────────────────────────

    #[Test]
    public function test_multiple_errors(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: [
                'Product not found: p-gone',
                'Insufficient stock for product p2: requested 5, available 2',
                'Price changed for product p3: expected 999, actual 1100',
            ],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertCount(3, $result->errors);
    }

    // ── Validated items contain correct prices ──────────────────────

    #[Test]
    public function test_validated_items_contain_current_prices(): void
    {
        $result = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => 'p1', 'quantity' => 1, 'unitPrice' => 4999, 'currency' => 'EUR'],
            ],
        );

        self::assertSame(4999, $result->validatedItems[0]['unitPrice']);
        self::assertSame('EUR', $result->validatedItems[0]['currency']);
    }
}
