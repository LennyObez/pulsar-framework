<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Http\Message\Response;

use function basename;
use function pathinfo;
use function strlen;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Public controller for digital product file downloads.
 *
 * Validates download tokens, decrements remaining download counts,
 * and streams the file with proper Content-Disposition and Content-Type headers.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class DigitalDownloadController
{
    private const array MIME_TYPES = [
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'epub' => 'application/epub+zip',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
    ];

    public function __construct(
        private DigitalDeliveryServiceInterface $delivery,
        private MediaDiskInterface $disk,
    ) {}

    public function download(string $token): Response
    {
        $result = $this->delivery->processDownload($token);

        if (!$result->success || $result->filePath === null) {
            return Response::json(['error' => 'Download not available'], 403);
        }

        if (!$this->disk->exists($result->filePath)) {
            return Response::json(['error' => 'File not found'], 404);
        }

        $content = $this->disk->read($result->filePath);
        $fileName = $result->fileName ?? basename($result->filePath);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $contentType = self::MIME_TYPES[$extension] ?? 'application/octet-stream';

        return new Response(
            headers: [
                'Content-Type' => $contentType,
                'Content-Disposition' => "attachment; filename=\"$fileName\"",
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => 'no-store',
                'X-Downloads-Remaining' => (string) ($result->downloadsRemaining ?? 0),
            ],
            body: $content,
        );
    }
}
