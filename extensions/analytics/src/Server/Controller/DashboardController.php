<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Http\Message\Response;

use function in_array;

/**
 * Serves the analytics dashboard HTML pages.
 */
#[Internal(reason: 'Analytics dashboard controller')]
final readonly class DashboardController
{
    public function __construct(
        private AnalyticsConfig $config,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        return $this->renderTemplate('dashboard');
    }

    public function sites(ServerRequestInterface $request): Response
    {
        return $this->renderTemplate('sites');
    }

    public function goals(ServerRequestInterface $request): Response
    {
        return $this->renderTemplate('goals');
    }

    public function settings(ServerRequestInterface $request): Response
    {
        return $this->renderTemplate('settings');
    }

    public function asset(ServerRequestInterface $request, string $path): Response
    {
        // Reject obviously malicious paths before filesystem access
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return Response::json(['error' => 'Not found'], 404);
        }

        // Only allow safe static asset extensions
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowedExtensions = ['css', 'js', 'svg', 'png', 'woff2', 'woff', 'ico'];

        if (!in_array($extension, $allowedExtensions, true)) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $basePath = __DIR__ . '/../View/assets/';
        $realBase = realpath($basePath);

        if ($realBase === false) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $fullPath = realpath($basePath . $path);

        if ($fullPath === false || !str_starts_with($fullPath, $realBase . DIRECTORY_SEPARATOR)) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $contentType = match ($extension) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        return new Response(
            headers: [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ],
            body: file_get_contents($fullPath) ?: '',
        );
    }

    private function renderTemplate(string $template): Response
    {
        $templatePath = __DIR__ . '/../View/templates/' . $template . '.php';

        if (!is_file($templatePath)) {
            return Response::json(['error' => 'Template not found'], 500);
        }

        ob_start();
        $config = $this->config;
        require $templatePath;
        $content = ob_get_clean();

        return Response::html($content ?: '');
    }
}
