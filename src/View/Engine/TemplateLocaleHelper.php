<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\I18n\Locale;
use Pulsar\I18n\Locale\LocaleUrlGenerator;
use Pulsar\I18n\TranslatorInterface;

/**
 * Minimal locale helper injected into compiled templates.
 *
 * Provides `current()`, `isRtl()`, `isActive()`, and `switchUrls()`
 * for locale-aware rendering and language switcher components.
 *
 * Reads the current locale from the translator, which is updated
 * per-request by the locale middleware, avoiding stale boot-time values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TemplateLocaleHelper
{
    public function __construct(
        private TranslatorInterface $translator,
        private ?LocaleUrlGenerator $urlGenerator = null,
    ) {}

    /**
     * Get the current request locale.
     */
    #[NoDiscard]
    public function current(): string
    {
        return $this->translator->locale;
    }

    /**
     * Check if the current locale uses a right-to-left script.
     */
    #[NoDiscard]
    public function isRtl(): bool
    {
        return Locale::parse($this->translator->locale)->isRtl();
    }

    /**
     * Check if the given locale matches the current locale.
     */
    #[NoDiscard]
    public function isActive(string $locale): bool
    {
        return $this->translator->locale === $locale;
    }

    /**
     * Get locale switcher URLs for the given path.
     *
     * Returns an empty array when no URL generator is available.
     *
     * @return array<string, string> Locale tag => URL
     */
    #[NoDiscard]
    public function switchUrls(string $currentPath): array
    {
        if ($this->urlGenerator === null) {
            return [];
        }

        return $this->urlGenerator->alternates($currentPath, $this->translator->locale);
    }
}
