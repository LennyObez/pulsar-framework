<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * CMS block: Product card with buy button.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $priceVal = $data['price_minor_units'] ?? null;
        $priceMinor = is_int($priceVal) ? $priceVal : (is_numeric($priceVal) ? (int) $priceVal : 0);
        $imageVal = $data['image_url'] ?? null;

        return new self(
            productName: self::str($data, 'product_name', ''),
            description: self::str($data, 'description', ''),
            priceDisplay: self::str($data, 'price_display', ''),
            priceMinorUnits: $priceMinor,
            currency: self::str($data, 'currency', 'USD'),
            imageUrl: is_string($imageVal) ? $imageVal : null,
            buyUrl: self::str($data, 'buy_url', '/checkout'),
            buttonText: self::str($data, 'button_text', 'Buy Now'),
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
