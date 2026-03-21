<?php

declare(strict_types=1);

namespace Pulsar\Http\Factory;

use NoDiscard;
use Override;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\UploadedFile;

use const UPLOAD_ERR_OK;

/**
 * PSR-17 uploaded file factory.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class UploadedFileFactory implements UploadedFileFactoryInterface
{
    #[NoDiscard]
    #[Override]
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        return new UploadedFile(
            streamOrFile: $stream,
            size: $size ?? $stream->getSize(),
            error: $error,
            clientFilename: $clientFilename,
            clientMediaType: $clientMediaType,
        );
    }
}
