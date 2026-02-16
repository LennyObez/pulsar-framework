<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function dirname;
use function is_file;
use function pathinfo;
use function preg_match;
use function realpath;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * Serves static assets for the CMS admin panel.
 *
 * In production, this controller handles asset requests routed through
 * the framework router. In development, the dev router serves assets
 * directly before kernel boot for better performance.
 */
#[Internal(reason: 'CMS admin asset serving; implementation detail')]
final readonly class AssetController
{
    private const array MIME_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];

    public function serve(ServerRequestInterface $request): Response
    {
        /** @var string $assetPath */
        $assetPath = $request->getAttribute('path', '');

        // Reject path traversal attempts and non-alphanumeric filenames
        if ($assetPath === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $assetPath) !== 1) {
            return new Response(
                statusCode: ResponseStatus::BadRequest->value,
                body: 'Invalid asset path',
            );
        }

        $baseDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR;

        $candidates = [
            $baseDir . 'styles' . DIRECTORY_SEPARATOR . $assetPath,
            $baseDir . 'dist' . DIRECTORY_SEPARATOR . $assetPath,
        ];

        foreach ($candidates as $filePath) {
            $realPath = realpath($filePath);

            if ($realPath === false || !is_file($realPath) || !str_starts_with($realPath, realpath($baseDir) ?: '')) {
                continue;
            }

            $ext = pathinfo($realPath, PATHINFO_EXTENSION);
            $contentType = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
            $content = (string) file_get_contents($realPath);

            return new Response(
                statusCode: ResponseStatus::OK->value,
                headers: [
                    'Content-Type' => $contentType,
                    'Cache-Control' => 'public, max-age=3600',
                ],
                body: $content,
            );
        }

        return new Response(
            statusCode: ResponseStatus::NotFound->value,
            body: 'Asset not found',
        );
    }
}
