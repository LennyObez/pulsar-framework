<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;
use SimpleXMLElement;

use function count;
use function is_string;
use function libxml_use_internal_errors;
use function simplexml_load_string;

/**
 * Admin controller for sitemap preview and regeneration.
 *
 * Provides a read-only preview of sitemap entries grouped by type
 * and locale, and the ability to force regeneration.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class SitemapController
{
    public function __construct(
        private SitemapGeneratorInterface $sitemapGenerator,
        private GateInterface $gate,
    ) {}

    /**
     * Preview sitemap entries grouped by type and locale with entry counts.
     */
    public function preview(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.view');

        $params = $request->getQueryParams();
        $baseUrl = is_string($params['base_url'] ?? null) ? $params['base_url'] : '';

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($baseUrl === '') {
            /** @var string $baseUrl */
            $baseUrl = $request->getAttribute('base_url', '');
        }

        $indexXml = $this->sitemapGenerator->generateIndex($baseUrl, $tenantId);

        $segments = $this->parseSitemapIndex($indexXml);

        return Response::json([
            'segments' => $segments,
            'total_segments' => count($segments),
            'base_url' => $baseUrl,
        ]);
    }

    /**
     * Force regeneration of the sitemap.
     */
    public function regenerate(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.manage');

        $params = $request->getQueryParams();
        $baseUrl = is_string($params['base_url'] ?? null) ? $params['base_url'] : '';

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($baseUrl === '') {
            /** @var string $baseUrl */
            $baseUrl = $request->getAttribute('base_url', '');
        }

        $indexXml = $this->sitemapGenerator->generateIndex($baseUrl, $tenantId);

        $segments = $this->parseSitemapIndex($indexXml);

        return Response::json([
            'regenerated' => true,
            'segments' => count($segments),
        ]);
    }

    /**
     * Parse sitemap index XML into segment metadata.
     *
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function parseSitemapIndex(string $xml): array
    {
        $segments = [];

        $previousErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($previousErrors);

        if ($doc instanceof SimpleXMLElement) {
            foreach ($doc->children('http://www.sitemaps.org/schemas/sitemap/0.9') as $child) {
                $loc = (string) $child->children('http://www.sitemaps.org/schemas/sitemap/0.9')->loc;
                $lastmod = (string) $child->children('http://www.sitemaps.org/schemas/sitemap/0.9')->lastmod;

                $segments[] = [
                    'loc' => $loc,
                    'lastmod' => $lastmod !== '' ? $lastmod : null,
                ];
            }
        }

        return $segments;
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
