<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * CMS block: Donation button with configurable amounts.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DonateButtonBlock
{
    /**
     * @param list<int> $suggestedAmounts Suggested donation amounts in minor units
     */
    public function __construct(
        public string $currency,
        public array $suggestedAmounts,
        public bool $allowCustomAmount,
        public string $buttonText,
        public string $successUrl,
        public string $cancelUrl,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawAmounts = (array) ($data['suggested_amounts'] ?? [500, 1000, 2500, 5000]);
        /** @var list<int> $amounts */
        $amounts = array_map(static fn(mixed $v): int => is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0), $rawAmounts);

        $currency = isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'USD';
        $buttonText = isset($data['button_text']) && is_string($data['button_text']) ? $data['button_text'] : 'Donate';
        $successUrl = isset($data['success_url']) && is_string($data['success_url']) ? $data['success_url'] : '/donate/thank-you';
        $cancelUrl = isset($data['cancel_url']) && is_string($data['cancel_url']) ? $data['cancel_url'] : '/';

        return new self(
            currency: $currency,
            suggestedAmounts: $amounts,
            allowCustomAmount: (bool) ($data['allow_custom_amount'] ?? true),
            buttonText: $buttonText,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );
    }

    /**
     * Render the donate button HTML.
     */
    public function render(): string
    {
        $buttons = '';

        foreach ($this->suggestedAmounts as $amount) {
            $formatted = number_format($amount / 100, 2);
            $buttons .= "<button type=\"button\" class=\"pulsar-donate-amount\" data-amount=\"$amount\">\$$formatted</button>\n";
        }

        $customInput = $this->allowCustomAmount
            ? '<input type="number" class="pulsar-donate-custom" placeholder="Custom amount" min="1" step="0.01">'
            : '';

        $buttonText = htmlspecialchars($this->buttonText, ENT_QUOTES | ENT_HTML5);

        return <<<HTML
            <div class="pulsar-donate-block" data-currency="{$this->currency}" data-success-url="{$this->successUrl}" data-cancel-url="{$this->cancelUrl}">
                <div class="pulsar-donate-amounts">{$buttons}</div>
                {$customInput}
                <button type="submit" class="pulsar-btn pulsar-btn-primary">{$buttonText}</button>
            </div>
            HTML;
    }
}
