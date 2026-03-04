<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

use function dirname;
use function file_get_contents;
use function is_dir;
use function is_file;
use function ltrim;
use function pathinfo;
use function realpath;
use function str_replace;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * Serves framework and extension UI assets (CSS, JS, fonts) via PHP routes.
 *
 * In production, a web server (Nginx/Apache) should serve these files
 * directly for better performance. This wiring provides a zero-config
 * fallback that works with the PHP built-in server and any environment
 * where `pulsar asset:publish` has not been run.
 *
 * Registered paths:
 *   /ui/{path}          -> resources/ui/{path}   (framework design system)
 *   /cms/assets/{path}  -> extensions/cms/resources/css/{path} (CMS styles)
 */
#[Internal]
final readonly class AssetWiring implements ServiceWiringInterface
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

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        // Resolve the framework's resources directory
        $frameworkRoot = dirname(__DIR__, 3);
        $uiPath = $frameworkRoot . '/resources/ui';
        $cmsAssetsPath = $frameworkRoot . '/extensions/cms/frontend/styles';

        // Only register asset routes if the assets exist (framework dev or symlink install)
        if (!is_dir($uiPath)) {
            return;
        }

        // /ui/{path} -> framework UI assets (CSS, fonts, JS)
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/ui/{path}',
            handler: static function (\Psr\Http\Message\ServerRequestInterface $request) use ($uiPath): Response {
                /** @var string $relativePath */
                $relativePath = $request->getAttribute('path', '');

                return self::serveFile($uiPath, $relativePath);
            },
            name: 'pulsar.assets.ui',
            constraints: ['path' => '.+'],
        ));

        // /cms/assets/{path} -> CMS extension CSS
        if (is_dir($cmsAssetsPath)) {
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/cms/assets/{path}',
                handler: static function (\Psr\Http\Message\ServerRequestInterface $request) use ($cmsAssetsPath): Response {
                    /** @var string $relativePath */
                    $relativePath = $request->getAttribute('path', '');

                    return self::serveFile($cmsAssetsPath, $relativePath);
                },
                name: 'pulsar.assets.cms',
                constraints: ['path' => '.+'],
            ));
        }
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
        $realFile = realpath($fullPath);

        // Ensure the resolved path is within the base directory
        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
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
