<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Exception\MissingTranslationException;
use Pulsar\I18n\Format\MessageFormatterInterface;

use function in_array;

/**
 * Main translator implementation.
 *
 * Resolves translations through the locale fallback chain and
 * formats messages using ICU MessageFormat or simple placeholders.
 */
#[Internal]
final class Translator implements TranslatorInterface
{
    private string $locale;

    private static ?self $globalInstance = null;

    public function __construct(
        private readonly CatalogInterface $catalog,
        private readonly I18nConfig $config,
        private readonly ?MessageFormatterInterface $formatter = null,
    ) {
        $this->locale = $config->defaultLocale;
    }

    public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        $targetLocale = $locale ?? $this->locale;

        // Build full fallback chain
        $localeChain = Locale::parse($targetLocale)->fallbackChain();

        foreach ($this->config->fallbackLocales as $fallback) {
            if (!in_array($fallback, $localeChain, true)) {
                $localeChain[] = $fallback;
            }
        }

        // Search through the chain
        foreach ($localeChain as $candidateLocale) {
            $entry = $this->catalog->get($key, $candidateLocale, $domain);

            if ($entry !== null) {
                return $this->formatMessage($entry->message, $parameters, $candidateLocale);
            }
        }

        // Not found
        if ($this->config->strictMode) {
            throw MissingTranslationException::forKey($key, $targetLocale, $domain);
        }

        return $this->formatMessage($key, $parameters, $targetLocale);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
    {
        $targetLocale = $locale ?? $this->locale;

        $localeChain = Locale::parse($targetLocale)->fallbackChain();

        foreach ($this->config->fallbackLocales as $fallback) {
            if (!in_array($fallback, $localeChain, true)) {
                $localeChain[] = $fallback;
            }
        }

        return array_any($localeChain, fn(string $candidateLocale): bool => $this->catalog->has($key, $candidateLocale, $domain));
    }

    /**
     * Set the global translator instance (called by I18nWiring).
     */
    public static function setGlobalInstance(self $instance): void
    {
        self::$globalInstance = $instance;
    }

    /**
     * Get the global translator instance.
     *
     * @throws I18nException If not booted
     */
    public static function getGlobalInstance(): self
    {
        return self::$globalInstance ?? throw I18nException::notBooted();
    }

    /**
     * Reset the global instance (for testing).
     */
    public static function resetGlobalInstance(): void
    {
        self::$globalInstance = null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function formatMessage(string $message, array $parameters, string $locale): string
    {
        if ($parameters === [] || $this->formatter === null) {
            return $message;
        }

        return $this->formatter->format($message, $parameters, $locale);
    }
}
