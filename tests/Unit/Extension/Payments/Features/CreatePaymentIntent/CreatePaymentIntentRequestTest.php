<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Features\CreatePaymentIntent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentRequest;

#[CoversClass(CreatePaymentIntentRequest::class)]
final class CreatePaymentIntentRequestTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $amount = Money::of(5000, Currency::USD);
        $request = new CreatePaymentIntentRequest($amount, 'idem-key-1', ['order' => '123']);

        self::assertSame($amount, $request->amount);
        self::assertSame('idem-key-1', $request->idempotencyKey);
        self::assertSame(['order' => '123'], $request->metadata);
    }

    #[Test]
    public function constructDefaultsMetadataToEmptyArray(): void
    {
        $amount = Money::of(1000, Currency::EUR);
        $request = new CreatePaymentIntentRequest($amount, 'idem-key-2');

        self::assertSame([], $request->metadata);
    }
}
