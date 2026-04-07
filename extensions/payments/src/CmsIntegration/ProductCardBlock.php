<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

/**
 * CMS block: Product card with buy button.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProductCardBlock
{
    public function __construct(
        public string $productName,
        public string $description,
        public string $priceDisplay,
        public int $priceMinorUnits,
        public string $currency,
        public ?string $imageUrl,
        public string $buyUrl,
        public string $buttonText,
    ) {}

    /**
     * @param array{
     *     product_name?: string,
     *     description?: string,
     *     price_display?: string,
     *     price_minor_units?: int|string,
     *     currency?: string,
     *     image_url?: string|null,
     *     buy_url?: string,
     *     button_text?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productName: $data['product_name'] ?? '',
            description: $data['description'] ?? '',
            priceDisplay: $data['price_display'] ?? '',
            priceMinorUnits: (int) ($data['price_minor_units'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            imageUrl: $data['image_url'] ?? null,
            buyUrl: $data['buy_url'] ?? '/checkout',
            buttonText: $data['button_text'] ?? 'Buy Now',
        );
    }

    /**
     * Render the product card HTML.
     */
    public function render(): string
    {
        $name = htmlspecialchars($this->productName, ENT_QUOTES | ENT_HTML5);
        $desc = htmlspecialchars($this->description, ENT_QUOTES | ENT_HTML5);
        $price = htmlspecialchars($this->priceDisplay, ENT_QUOTES | ENT_HTML5);
        $button = htmlspecialchars($this->buttonText, ENT_QUOTES | ENT_HTML5);
        $buyUrl = htmlspecialchars($this->buyUrl, ENT_QUOTES | ENT_HTML5);

        $image = $this->imageUrl !== null
            ? '<img src="' . htmlspecialchars($this->imageUrl, ENT_QUOTES | ENT_HTML5) . '" alt="' . $name . '" class="pulsar-product-image" loading="lazy">'
            : '';

        return <<<HTML
            <div class="pulsar-product-card" data-price="{$this->priceMinorUnits}" data-currency="{$this->currency}">
                {$image}
                <div class="pulsar-product-info">
                    <h3 class="pulsar-product-name">{$name}</h3>
                    <p class="pulsar-product-description">{$desc}</p>
                    <div class="pulsar-product-price">{$price}</div>
                    <a href="{$buyUrl}" class="pulsar-btn pulsar-btn-primary">{$button}</a>
                </div>
            </div>
            HTML;
    }
}
