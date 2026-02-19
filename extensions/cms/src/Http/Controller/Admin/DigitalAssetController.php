<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function bin2hex;
use function dechex;
use function file_exists;
use function file_get_contents;
use function hash_file;
use function hexdec;
use function is_string;
use function max;
use function microtime;
use function random_bytes;
use function sprintf;
use function str_pad;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const STR_PAD_LEFT;

/**
 * Admin controller for managing digital assets attached to products.
 *
 * Handles listing, uploading, and deleting downloadable files for digital products.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class DigitalAssetController
{
    public function __construct(
        private DigitalAssetRepositoryInterface $assets,
        private ProductRepositoryInterface $products,
        private MediaDiskInterface $disk,
        private GateInterface $gate,
    ) {}

    public function index(ServerRequestInterface $request, string $productId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.view');

        $product = $this->products->findById($productId);

        if ($product === null) {
            return Response::json(['error' => 'Product not found'], 404);
        }

        $assets = $this->assets->findByProduct($productId);

        return Response::json([
            'product_id' => $productId,
            'data' => array_map(static fn(DigitalAsset $a) => [
                'id' => $a->id,
                'file_name' => $a->fileName,
                'file_size' => $a->fileSize,
                'file_hash' => $a->fileHash,
                'max_downloads' => $a->maxDownloads,
            ], $assets),
        ]);
    }

    public function upload(ServerRequestInterface $request, string $productId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.edit');

        $product = $this->products->findById($productId);

        if ($product === null) {
            return Response::json(['error' => 'Product not found'], 404);
        }

        if (!$product->isDigital()) {
            return Response::json(['error' => 'Product is not a digital product'], 422);
        }

        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No valid file uploaded'], 400);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $maxDownloads = max(1, (int) ($body['max_downloads'] ?? 5));

        $tempPath = tempnam(sys_get_temp_dir(), 'pulsar_digital_');

        if ($tempPath === false) {
            return Response::json(['error' => 'Failed to create temporary file'], 500);
        }

        $file->moveTo($tempPath);

        $fileHash = hash_file('sha256', $tempPath);

        if ($fileHash === false) {
            return Response::json(['error' => 'Failed to compute file hash'], 500);
        }

        $assetId = $this->generateUuidV7();
        $fileName = is_string($file->getClientFilename()) ? $file->getClientFilename() : 'download';
        $storagePath = "digital-assets/{$productId}/{$assetId}/{$fileName}";
        $fileSize = (int) $file->getSize();

        $this->disk->write($storagePath, file_get_contents($tempPath) ?: '');

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $asset = new DigitalAsset(
            id: $assetId,
            productId: $productId,
            fileStoragePath: $storagePath,
            fileHash: $fileHash,
            fileName: $fileName,
            fileSize: $fileSize,
            maxDownloads: $maxDownloads,
        );

        $this->assets->save($asset);

        return Response::json([
            'id' => $assetId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_hash' => $fileHash,
            'status' => 'uploaded',
        ], 201);
    }

    /**
     * Delete a digital asset from a product.
     *
     * The product ID is required to locate the asset within the product's
     * asset collection, as the repository indexes assets by product.
     */
    public function delete(ServerRequestInterface $request, string $productId, string $assetId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.edit');

        $assets = $this->assets->findByProduct($productId);
        $target = null;

        foreach ($assets as $asset) {
            if ($asset->id === $assetId) {
                $target = $asset;

                break;
            }
        }

        if ($target === null) {
            return Response::json(['error' => 'Digital asset not found'], 404);
        }

        $this->disk->delete($target->fileStoragePath);

        return Response::json(['id' => $assetId, 'status' => 'deleted']);
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

    private function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
