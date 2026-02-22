<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\TranslatorInterface;

/**
 * HTTP middleware that negotiates the request locale.
 *
 * Sets the `_locale` request attribute and updates the translator's
 * current locale for each request.
 */
#[Internal]
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LocaleNegotiatorInterface $negotiator,
        private I18nConfig $config,
        private TranslatorInterface $translator,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->negotiator->negotiate(
            $request,
            $this->config->supportedLocales,
            $this->config->defaultLocale,
        );

        $this->translator->locale = $locale;

        $request = $request->withAttribute('_locale', $locale);

        return $handler->handle($request);
    }
}
