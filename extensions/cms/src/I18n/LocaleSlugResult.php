<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\Redirect;

/**
 * Result of locale + slug resolution.
 *
 * Contains the resolved locale, content path, and either a matched
 * translation or a redirect. When isFallback is true, the translation
 * was resolved from the default locale because the requested locale
 * had no translation.
 *
 * @psalm-api Returned from LocaleSlugResolver::resolve(); consumed by
 *            content controllers and middleware.
 */
#[Internal(reason: 'CMS i18n resolution result; implementation detail')]
final readonly class LocaleSlugResult
{
    public function __construct(
        public string $locale,
        public string $contentPath,
        public ?ContentTranslation $translation,
        public ?Redirect $redirect,
        public bool $isFallback = false,
    ) {}

    public function isRedirect(): bool
    {
        return $this->redirect !== null;
    }

    public function hasTranslation(): bool
    {
        return $this->translation !== null;
    }
}
