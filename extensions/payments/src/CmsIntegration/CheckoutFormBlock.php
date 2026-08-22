<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

/**
 * CMS block: Embedded checkout form.
 *
 * Renders a secure, PCI-compliant checkout form that tokenizes
 * card data client-side using Stripe Elements or PayPal Buttons.
 * @api
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
     * @param array{
     *     gateway?: string,
     *     publishable_key?: string,
     *     success_url?: string,
     *     cancel_url?: string,
     *     fixed_amount?: int|string|null,
     *     currency?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $fixedAmountRaw = $data['fixed_amount'] ?? null;

        return new self(
            gateway: $data['gateway'] ?? 'stripe',
            publishableKey: $data['publishable_key'] ?? '',
            successUrl: $data['success_url'] ?? '/checkout/success',
            cancelUrl: $data['cancel_url'] ?? '/',
            fixedAmount: $fixedAmountRaw !== null ? (int) $fixedAmountRaw : null,
            currency: $data['currency'] ?? 'USD',
        );
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
