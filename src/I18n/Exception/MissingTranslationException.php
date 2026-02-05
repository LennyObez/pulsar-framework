<?php

declare(strict_types=1);

namespace Pulsar\I18n\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

#[Api(since: '1.0.0')]
final class MissingTranslationException extends I18nException
{
    #[NoDiscard]
    public static function forKey(string $key, string $locale, string $domain): self
    {
        return new self(sprintf(
            'Missing translation for key "%s" in locale "%s", domain "%s"',
            $key,
            $locale,
            $domain,
        ));
    }
}
