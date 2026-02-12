<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;

/**
 * Value object wrapping a BCP 47 locale tag.
 *
 * Provides parsing, fallback chain generation, and RTL detection.
 */
#[Api(since: '1.0.0')]
readonly class Locale
{
    /** @var list<string> Languages that use right-to-left scripts */
    private const array RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur'];

    public function __construct(
        public string $tag,
        public string $language,
        public ?string $region,
    ) {}

    /**
     * Parse a locale tag string into a Locale value object.
     *
     * Supports both underscore (fr_CA) and hyphen (fr-CA) separators.
     */
    #[NoDiscard]
    public static function parse(string $tag): self
    {
        $normalized = str_replace('-', '_', $tag);
        $separatorPos = strpos($normalized, '_');

        if ($separatorPos === false) {
            return new self(
                tag: $normalized,
                language: strtolower($normalized),
                region: null,
            );
        }

        return new self(
            tag: $normalized,
            language: strtolower(substr($normalized, 0, $separatorPos)),
            region: substr($normalized, $separatorPos + 1),
        );
    }

    /**
     * Generate the fallback chain for this locale.
     *
     * For 'fr_CA' returns ['fr_CA', 'fr'].
     * For 'fr' returns ['fr'].
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function fallbackChain(): array
    {
        if ($this->region === null) {
            return [$this->tag];
        }

        return [$this->tag, $this->language];
    }

    /**
     * Check if this locale uses a right-to-left script.
     */
    #[NoDiscard]
    public function isRtl(): bool
    {
        return in_array($this->language, self::RTL_LANGUAGES, true);
    }
}
