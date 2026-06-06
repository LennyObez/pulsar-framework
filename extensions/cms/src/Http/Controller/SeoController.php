<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;
use Pulsar\Http\Message\Response;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Serves robots.txt, search engine verification files, and other SEO-related public endpoints.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class SeoController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private RobotsTxtGeneratorInterface $robotsTxtGenerator,
        private SeoConfig $seoConfig,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function robotsTxt(ServerRequestInterface $request): Response
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();

        if ($port !== null && $port !== 80 && $port !== 443) {
            $baseUrl .= ':' . $port;
        }

        $content = $this->robotsTxtGenerator->generate($baseUrl);

        return Response::text($content)
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Serve Google Search Console verification HTML file.
     *
     * Google expects: GET /google{code}.html returning
     * "google-site-verification: google{code}.html"
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function googleVerification(): Response
    {
        $code = $this->seoConfig->googleSiteVerification;

        if ($code === null || $code === '') {
            return Response::text('Not found', 404);
        }

        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $filename = sprintf('google%s.html', $safeCode);

        return Response::html(
            sprintf('google-site-verification: %s', $filename),
        )->withHeader('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Serve Bing Webmaster Tools verification XML file.
     *
     * Bing expects: GET /BingSiteAuth.xml returning XML with the verification code.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function bingVerification(): Response
    {
        $code = $this->seoConfig->bingSiteVerification;

        if ($code === null || $code === '') {
            return Response::text('Not found', 404);
        }

        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        $xml = <<<XML
            <?xml version="1.0"?>
            <users>
                <user>{$safeCode}</user>
            </users>
            XML;

        return new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=86400'],
            body: $xml,
        );
    }
}
