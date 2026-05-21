<?php

declare(strict_types=1);

namespace Pulsar\I18n\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function implode;
use function sprintf;

#[Api(since: '1.0.0')]
class I18nException extends RuntimeException
{
    #[NoDiscard]
    public static function intlRequired(): self
    {
        return new self('The ext-intl PHP extension is required in regulated mode but is not loaded');
    }

    #[NoDiscard]
    public static function notBooted(): self
    {
        return new self('I18n system has not been booted. Ensure I18nWiring is registered and config/i18n.php exists');
    }

    /**
     * @param list<string> $supported
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function unsupportedLocale(string $locale, array $supported): self
    {
        return new self(sprintf(
            'Locale "%s" is not in the supported locales list: [%s]',
            $locale,
            implode(', ', $supported),
        ));
    }
}
