<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Seo\StructuredDataGeneratorInterface;

use function ltrim;
use function number_format;
use function rtrim;
use function sprintf;

/**
 * Generates Product schema.org structured data for content linked to a commerce product.
 */
#[Internal(reason: 'Use StructuredDataGeneratorInterface for public API')]
final readonly class ProductStructuredDataGenerator implements StructuredDataGeneratorInterface
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
    ) {}

    public function supports(Content $content): bool
    {
        // A content item linked to a product is eligible for Product structured data
        return $this->findLinkedProduct($content) !== null;
    }

    public function generate(Content $content, ContentTranslation $translation, string $baseUrl): array
    {
        $product = $this->findLinkedProduct($content);

        if ($product === null) {
            return [];
        }

        $baseUrl = rtrim($baseUrl, '/');
        $url = sprintf('%s/%s', $baseUrl, ltrim($translation->path, '/'));
        $priceDecimal = number_format($product->priceAmount / 100, 2, '.', '');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $translation->metaTitle ?? $translation->title,
            'url' => $url,
            'sku' => $product->sku,
            'offers' => [
                '@type' => 'Offer',
                'price' => $priceDecimal,
                'priceCurrency' => $product->priceCurrency,
                'availability' => $product->isInStock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];

        if ($translation->metaDescription !== null) {
            $data['description'] = $translation->metaDescription;
        }

        return $data;
    }

    private function findLinkedProduct(Content $content): ?Product
    {
        return $this->productRepository->findByContentId($content->id);
    }
}
