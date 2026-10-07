<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Override;
use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @region_selector directive to render the region/language selector.
 *
 * Usage:
 *   @region_selector                         : default 'region' mode
 *   @region_selector('full')                 : full mode (region + language + currency)
 *   @region_selector('language')             : language-only mode (backward compat)
 *
 * Renders a `<div data-language-selector>` element with the appropriate
 * mode and feature attributes for the frontend JavaScript component.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class RegionSelectorDirective implements DirectiveInterface
{
    #[Override]
    public function name(): string
    {
        return 'region_selector';
    }

    #[Override]
    public function compile(string $expression): string
    {
        $expr = trim($expression);

        if ($expr === '') {
            $expr = "'region'";
        }

        return sprintf(
            <<<'PHP'
                <?php
                $__rs_mode = %s;
                $__rs_country = $__request->getAttribute('_region_country');
                $__rs_locale = $__request->getAttribute('_locale', 'en');
                $__rs_currency = $__request->getAttribute('_region_currency', 'USD');
                $__rs_code = $__rs_country ? $__rs_country->code : 'US';
                $__rs_flag = $__rs_country ? $__rs_country->flag : '';
                $__rs_locales = implode(',', $__i18nConfig->supportedLocales ?? ['en']);
                ?>
                <div
                    data-language-selector
                    data-selector-mode="<?= htmlspecialchars($__rs_mode, ENT_QUOTES, 'UTF-8') ?>"
                    data-locales="<?= htmlspecialchars($__rs_locales, ENT_QUOTES, 'UTF-8') ?>"
                    data-current="<?= htmlspecialchars($__rs_locale, ENT_QUOTES, 'UTF-8') ?>"
                    data-country="<?= htmlspecialchars($__rs_code, ENT_QUOTES, 'UTF-8') ?>"
                    data-currency="<?= htmlspecialchars($__rs_currency, ENT_QUOTES, 'UTF-8') ?>"
                    data-flag="<?= $__rs_flag ?>"
                ></div>
                <?php unset($__rs_mode, $__rs_country, $__rs_locale, $__rs_currency, $__rs_code, $__rs_flag, $__rs_locales); ?>
                PHP,
            $expr,
        );
    }
}
