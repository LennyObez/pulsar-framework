<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * CMS block: Embedded checkout form.
 *
 * Renders a secure, PCI-compliant checkout form that tokenizes
 * card data client-side using Stripe Elements or PayPal Buttons.
 */
#[Api(since: '1.0.0')]
final readonly class CheckoutFormBlock
{
    public function __construct(
        public string $gateway,
        public string $publishableKey,
        public string $successUrl,
        public string $cancelUrl,
        public ?int $fixedAmount,
        public string $currency,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fixedAmountRaw = $data['fixed_amount'] ?? null;
        $fixedAmount = is_int($fixedAmountRaw) ? $fixedAmountRaw : (is_numeric($fixedAmountRaw) ? (int) $fixedAmountRaw : null);

        return new self(
            gateway: self::str($data, 'gateway', 'stripe'),
            publishableKey: self::str($data, 'publishable_key', ''),
            successUrl: self::str($data, 'success_url', '/checkout/success'),
            cancelUrl: self::str($data, 'cancel_url', '/'),
            fixedAmount: $fixedAmount,
            currency: self::str($data, 'currency', 'USD'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Render the checkout form HTML.
     */
    public function render(): string
    {
        $gateway = htmlspecialchars($this->gateway, ENT_QUOTES | ENT_HTML5);
        $key = htmlspecialchars($this->publishableKey, ENT_QUOTES | ENT_HTML5);
        $successUrl = htmlspecialchars($this->successUrl, ENT_QUOTES | ENT_HTML5);
        $amountAttr = $this->fixedAmount !== null ? "data-amount=\"{$this->fixedAmount}\"" : '';

        return <<<HTML
            <div class="pulsar-checkout-form"
                 data-gateway="{$gateway}"
                 data-key="{$key}"
                 data-success-url="{$successUrl}"
                 data-currency="{$this->currency}"
                 {$amountAttr}>
                <div id="pulsar-payment-element"></div>
                <button type="submit" class="pulsar-btn pulsar-btn-primary pulsar-checkout-submit">
                    Pay Now
                </button>
                <div class="pulsar-checkout-error" role="alert"></div>
            </div>
            HTML;
    }
}
