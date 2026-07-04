<?php

declare(strict_types=1);

namespace Pulsar\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

use function dirname;
use function file_get_contents;
use function is_file;
use function is_string;
use function ltrim;
use function pathinfo;
use function realpath;
use function str_replace;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * Serves framework and extension UI assets (CSS, JS, fonts) via class-based,
 * cacheable route handlers.
 *
 * Extracted from {@see \Pulsar\Core\Wiring\AssetWiring} so the asset routes use
 * `[AssetController::class, 'ui'|'cms']` handlers instead of closures: closures
 * cannot be serialized into the compiled route cache (`optimize --strict`), so a
 * class-based handler keeps the production route cache complete.
 *
 * In production a web server should serve these files directly; this is a
 * zero-config fallback for the PHP built-in server and installs where
 * `pulsar asset:publish` has not run.
 */
#[Internal]
final readonly class AssetController
{
    private const array MIME_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'json' => 'application/json',
    ];

    /** Framework design-system assets root (`resources/ui`). */
    public static function uiAssetRoot(): string
    {
        return dirname(__DIR__, 3) . '/resources/ui';
    }

    /** CMS extension styles root (`extensions/cms/frontend/styles`). */
    public static function cmsAssetRoot(): string
    {
        return dirname(__DIR__, 3) . '/extensions/cms/frontend/styles';
    }

    /** GET /ui/{path} — framework UI assets (CSS, fonts, JS). */
    public function ui(ServerRequestInterface $request): Response
    {
        return self::serveFile(self::uiAssetRoot(), self::relativePath($request));
    }

    /** GET /cms/assets/{path} — CMS extension styles. */
    public function cms(ServerRequestInterface $request): Response
    {
        return self::serveFile(self::cmsAssetRoot(), self::relativePath($request));
    }

    private static function relativePath(ServerRequestInterface $request): string
    {
        /** @var mixed $path */
        $path = $request->getAttribute('path', '');

        return is_string($path) ? $path : '';
    }

    private static function serveFile(string $basePath, string $relativePath): Response
    {
        // Security: prevent directory traversal
        $cleanPath = str_replace(['..', "\0"], '', $relativePath);
        $cleanPath = ltrim($cleanPath, '/');

        if ($cleanPath === '' || str_starts_with($cleanPath, '.')) {
            return new Response(statusCode: 404);
        }

        $fullPath = $basePath . '/' . $cleanPath;
        $realBase = realpath($basePath);
        if ($realBase === false) {
            return new Response(statusCode: 404);
        }

        $realFile = realpath($fullPath);
        if ($realFile === false) {
            return new Response(statusCode: 404);
        }

        // Ensure the resolved path is within the base directory
        if (!str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return new Response(statusCode: 404);
        }

        if (!is_file($realFile)) {
            return new Response(statusCode: 404);
        }

        $extension = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mimeType = self::MIME_TYPES[$extension] ?? 'application/octet-stream';
        $content = file_get_contents($realFile);

        if ($content === false) {
            return new Response(statusCode: 500);
        }

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=86400, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ],
            body: $content,
        );
    }
}
