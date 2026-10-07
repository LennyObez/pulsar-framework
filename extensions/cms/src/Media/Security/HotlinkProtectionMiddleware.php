<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Security;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function file_exists;
use function file_get_contents;
use function is_string;
use function parse_url;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const PHP_URL_HOST;

/**
 * Prevents hotlinking of media assets by checking the Referer header
 * against a configurable list of allowed domains.
 *
 * Returns 403 for unauthorized requests or serves a placeholder image.
 */
#[Internal(reason: 'CMS media security middleware')]
final readonly class HotlinkProtectionMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedDomains Domains permitted to reference media assets
     * @param bool $enabled Whether hotlink protection is active
     * @param string|null $placeholderPath Path to a placeholder image served for blocked requests
     */
    public function __construct(
        private array $allowedDomains = [],
        private bool $enabled = true,
        private ?string $placeholderPath = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $referer = $request->getHeaderLine('Referer');

        // Allow direct access (no referer; bookmarks, address bar, etc.)
        if ($referer === '') {
            return $handler->handle($request);
        }

        $refererHost = $this->extractHost($referer);

        if ($refererHost === null) {
            return $handler->handle($request);
        }

        // Check if referer is from an allowed domain
        $requestHost = $this->extractHost(
            ($request->getUri()->getScheme() ?: 'https') . '://' . $request->getUri()->getHost(),
        );

        // Always allow same-origin requests
        if ($requestHost !== null && $refererHost === $requestHost) {
            return $handler->handle($request);
        }

        // Check against allowed domains list
        if ($this->isDomainAllowed($refererHost)) {
            return $handler->handle($request);
        }

        // Blocked; return 403 or placeholder image
        if ($this->placeholderPath !== null && file_exists($this->placeholderPath)) {
            $contents = file_get_contents($this->placeholderPath);

            if ($contents !== false) {
                return new Response(
                    statusCode: 403,
                    headers: [
                        'Content-Type' => 'image/png',
                        'Cache-Control' => 'no-store',
                    ],
                    body: $contents,
                );
            }
        }

        return new Response(
            statusCode: 403,
            headers: [
                'Content-Type' => 'text/plain',
                'Cache-Control' => 'no-store',
            ],
            body: 'Hotlinking not allowed',
        );
    }

    private function extractHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower(trim($host));
    }

    private function isDomainAllowed(string $host): bool
    {
        foreach ($this->allowedDomains as $allowed) {
            $normalizedAllowed = strtolower(trim($allowed));

            if ($host === $normalizedAllowed) {
                return true;
            }

            // Support wildcard subdomains: *.example.com matches sub.example.com
            if (str_starts_with($normalizedAllowed, '*.')) {
                $suffix = substr($normalizedAllowed, 1); // ".example.com"

                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
