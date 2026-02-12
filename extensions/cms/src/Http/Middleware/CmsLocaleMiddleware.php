<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\I18n\LocaleResolver;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function in_array;

/**
 * Resolves the active locale from the request and validates it
 * against the supported locales configuration.
 *
 * Sets the resolved locale as a request attribute (`cms_locale`)
 * for downstream controllers and middleware. Returns 404 if the
 * locale extracted from the URL prefix is not in the supported list.
 */
#[Internal(reason: 'CMS middleware — not a public API surface')]
final readonly class CmsLocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LocaleResolver $localeResolver,
        private CmsConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->localeResolver->resolve($request, $this->config);

        // Validate the resolved locale is in the supported list
        if (!in_array($locale, $this->config->supportedLocales, true)) {
            return Response::json(
                ['error' => 'Unsupported locale', 'status' => 404],
                404,
            );
        }

        // Set locale on request attributes for downstream use
        $request = $request->withAttribute('cms_locale', $locale);

        return $handler->handle($request);
    }
}
