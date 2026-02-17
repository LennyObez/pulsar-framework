<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\PaymentResult;

#[CoversClass(PaymentResult::class)]
final class PaymentResultTest extends TestCase
{
    #[Test]
    public function successfulPaymentWithNoRedirect(): void
    {
        $result = new PaymentResult(
            success: true,
            orderId: 'ord-001',
            paymentIntentId: 'pi_abc123',
            requiresRedirect: false,
            redirectUrl: null,
        );

        self::assertTrue($result->success);
        self::assertSame('ord-001', $result->orderId);
        self::assertSame('pi_abc123', $result->paymentIntentId);
        self::assertFalse($result->requiresRedirect);
        self::assertNull($result->redirectUrl);
    }

    #[Test]
    public function paymentRequiringRedirect(): void
    {
        $result = new PaymentResult(
            success: false,
            orderId: 'ord-002',
            paymentIntentId: 'pi_xyz789',
            requiresRedirect: true,
            redirectUrl: 'https://stripe.com/3ds/confirm',
        );

        self::assertFalse($result->success);
        self::assertTrue($result->requiresRedirect);
        self::assertSame('https://stripe.com/3ds/confirm', $result->redirectUrl);
    }
}
