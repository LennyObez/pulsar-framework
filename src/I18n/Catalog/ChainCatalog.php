<?php

declare(strict_types=1);

namespace Pulsar\I18n\Catalog;

use Pulsar\Api\Internal;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\TranslationEntry;

use function array_values;

/**
 * Chains multiple catalog backends, trying each in order.
 *
 * The first catalog that contains a key wins.
 */
#[Internal]
final readonly class ChainCatalog implements CatalogInterface
{
    /** @var list<CatalogInterface> */
    private array $catalogs;

    public function __construct(CatalogInterface ...$catalogs)
    {
        $this->catalogs = array_values($catalogs);
    }

    public function get(string $key, string $locale, string $domain = 'messages'): ?TranslationEntry
    {
        foreach ($this->catalogs as $catalog) {
            $entry = $catalog->get($key, $locale, $domain);

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    public function has(string $key, string $locale, string $domain = 'messages'): bool
    {
        return array_any($this->catalogs, static fn(CatalogInterface $catalog): bool => $catalog->has($key, $locale, $domain));
    }

    /**
     * @return array<string, TranslationEntry>
     */
    public function all(string $locale, string $domain = 'messages'): array
    {
        $merged = [];

        foreach ($this->catalogs as $catalog) {
            foreach ($catalog->all($locale, $domain) as $key => $entry) {
                $merged[$key] ??= $entry;
            }
        }

        return $merged;
    }
}
