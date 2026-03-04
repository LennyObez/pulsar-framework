<?php

declare(strict_types=1);

namespace Pulsar\Http\Controller\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Message\Response;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Compiler\TranslationCompiler;

use function in_array;
use function is_string;
use function preg_match;

/**
 * Serves translation bundles as JSON for client-side i18n.
 *
 * Endpoint: GET /api/i18n/{locale}.json
 *
 * Responds with an immutable cache header for production use and a flat
 * key-value JSON object containing all translations for the requested locale.
 */
#[Internal]
final readonly class I18nController
{
    private TranslationCompiler $compiler;

    /**
     * @param list<string> $domains Domains to include in the bundle
     */
    public function __construct(
        private CatalogInterface $catalog,
        private I18nConfig $config,
        private array $domains = ['core', 'messages'],
    ) {
        $this->compiler = new TranslationCompiler($this->catalog);
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $locale = $request->getAttribute('locale', '');

        if (!is_string($locale) || $locale === '' || !preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/', $locale)) {
            return Response::json(
                data: ['error' => 'Invalid locale format'],
                status: 400,
            );
        }

        if (!in_array($locale, $this->config->supportedLocales, true)) {
            return Response::json(
                data: ['error' => 'Unsupported locale'],
                status: 404,
            );
        }

        $json = $this->compiler->compileAll($locale, $this->domains);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Vary' => 'Accept-Encoding',
            ],
            body: $json,
        );
    }
}
