<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

use function dirname;
use function file_get_contents;
use function hash;
use function in_array;
use function is_file;
use function substr;

/**
 * Serves the managed-challenge widget, worker, and proof-of-work core as
 * same-origin JavaScript so the feature works under a strict
 * `script-src 'self'` / `worker-src 'self'` Content Security Policy — no CDN,
 * no inline code, no blob: worker.
 *
 * Assets are framework-shipped (resources/ui/js); responses carry a content
 * ETag with a short max-age so browsers cache aggressively yet pick up updates
 * after a framework upgrade via cheap revalidation.
 */
#[Internal(reason: 'Managed challenge asset endpoints; wired by AntiSpamWiring')]
final readonly class ManagedChallengeAssetController
{
    /** Allowlist of servable files (defends the join against traversal). */
    private const array FILES = [
        'managed-challenge.js',
        'managed-challenge.worker.js',
        'managed-challenge.pow.js',
        'behavior-collector.js',
    ];

    public function widget(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve($request, 'managed-challenge.js');
    }

    public function worker(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve($request, 'managed-challenge.worker.js');
    }

    public function pow(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve($request, 'managed-challenge.pow.js');
    }

    public function behaviorCollector(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve($request, 'behavior-collector.js');
    }

    private function serve(ServerRequestInterface $request, string $file): ResponseInterface
    {
        if (!in_array($file, self::FILES, true)) {
            return new Response(statusCode: 404);
        }

        $path = dirname(__DIR__, 4) . '/resources/ui/js/' . $file;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            return new Response(statusCode: 404);
        }

        $etag = '"' . substr(hash('sha256', $contents), 0, 32) . '"';

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new Response(statusCode: 304, headers: [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => $etag,
                'X-Content-Type-Options' => 'nosniff',
            ],
            body: $contents,
        );
    }
}
