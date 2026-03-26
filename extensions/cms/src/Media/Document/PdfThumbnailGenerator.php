<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Document;

use Imagick;
use ImagickException;
use ImagickPixel;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function class_exists;
use function file_exists;
use function is_resource;
use function realpath;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Generates thumbnail preview images from the first page of PDF files.
 *
 * Supports two backends:
 * - Imagick (ext-imagick): Preferred, uses Ghostscript internally
 * - Ghostscript CLI (gs): Fallback when Imagick is unavailable
 *
 * The generated thumbnail is a JPEG image at configurable resolution.
 *
 * @psalm-api Resolved from the DI container by document derivative jobs;
 *            not instantiated by name.
 */
#[Internal(reason: 'Use PdfThumbnailGenerator via service container')]
final readonly class PdfThumbnailGenerator
{
    private const int DEFAULT_WIDTH = 600;
    private const int DEFAULT_HEIGHT = 800;
    private const int DEFAULT_DPI = 150;
    private const int JPEG_QUALITY = 85;

    public function __construct(
        private LoggerInterface $logger,
        private string $ghostscriptPath = 'gs',
    ) {}

    /**
     * Generate a JPEG thumbnail from the first page of a PDF file.
     *
     * @param int $width Target thumbnail width in pixels
     * @param int $height Target thumbnail height in pixels
     *
     * @return string|null Path to the generated thumbnail, or null on failure
     */
    public function generate(
        string $pdfPath,
        int $width = self::DEFAULT_WIDTH,
        int $height = self::DEFAULT_HEIGHT,
    ): ?string {
        if (!file_exists($pdfPath)) {
            $this->logger->warning('PDF file not found for thumbnail generation', [
                'file' => $pdfPath,
            ]);

            return null;
        }

        if (class_exists(Imagick::class)) {
            return $this->generateWithImagick($pdfPath, $width, $height);
        }

        return $this->generateWithGhostscript($pdfPath, $width, $height);
    }

    private function generateWithImagick(string $pdfPath, int $width, int $height): ?string
    {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(self::DEFAULT_DPI, self::DEFAULT_DPI);
            $imagick->readImage($pdfPath . '[0]'); // First page only
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
            $imagick->thumbnailImage($width, $height, true);

            // Flatten transparency to white background
            $background = new Imagick();
            $background->newImage(
                $imagick->getImageWidth(),
                $imagick->getImageHeight(),
                new ImagickPixel('white'),
            );
            $background->setImageFormat('jpeg');
            $background->compositeImage($imagick, Imagick::COMPOSITE_OVER, 0, 0);

            $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_pdf_thumb_');

            if ($tempFile === false) {
                $imagick->destroy();
                $background->destroy();

                return null;
            }

            $outputPath = $tempFile . '.jpg';
            $background->writeImage($outputPath);

            $imagick->destroy();
            $background->destroy();

            // Clean up the extensionless temp file
            if ($tempFile !== $outputPath) {
                self::cleanupTempFile($tempFile);
            }

            $this->logger->info('PDF thumbnail generated via Imagick', [
                'source' => $pdfPath,
                'output' => $outputPath,
            ]);

            return $outputPath;
        } catch (ImagickException $e) {
            $this->logger->warning('Imagick PDF thumbnail generation failed', [
                'file' => $pdfPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function generateWithGhostscript(string $pdfPath, int $width, int $height): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_pdf_thumb_');

        if ($tempFile === false) {
            return null;
        }

        $outputPath = $tempFile . '.jpg';

        // Calculate DPI from target dimensions (assuming letter-sized page 8.5x11)
        $dpiX = (int) ($width / 8.5 * 72);
        $dpiY = (int) ($height / 11.0 * 72);
        $dpi = max($dpiX, $dpiY);

        $args = [
            $this->ghostscriptPath,
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-dFirstPage=1',
            '-dLastPage=1',
            '-sDEVICE=jpeg',
            '-dJPEGQ=' . self::JPEG_QUALITY,
            '-r' . $dpi,
            '-sOutputFile=' . $outputPath,
            $pdfPath,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // proc_open() receives an argv array (PHP 7.4+), so the shell is never
        // involved -- no metacharacter expansion, no command-injection surface.
        // $this->ghostscriptPath is server config; $pdfPath is validated upstream.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $process = @proc_open($args, $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->logger->warning('Failed to start Ghostscript process');
            self::cleanupTempFile($tempFile);

            return null;
        }

        /** @var array<int, resource> $pipes */
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            $this->logger->warning('Ghostscript PDF thumbnail generation failed', [
                'file' => $pdfPath,
                'exit_code' => $exitCode,
            ]);

            self::cleanupTempFile($outputPath);
            self::cleanupTempFile($tempFile);

            return null;
        }

        if ($tempFile !== $outputPath) {
            self::cleanupTempFile($tempFile);
        }

        $this->logger->info('PDF thumbnail generated via Ghostscript', [
            'source' => $pdfPath,
            'output' => $outputPath,
        ]);

        return $outputPath;
    }

    /**
     * Safely remove a temporary file after verifying it resides inside the
     * system temp directory. Guards against path-traversal: only files whose
     * resolved real path starts with sys_get_temp_dir() (with an explicit
     * trailing separator) are deleted, so a directory whose name shares a
     * prefix with the temp dir cannot escape the boundary.
     */
    private static function cleanupTempFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $realPath = realpath($path);
        $tempDir = realpath(sys_get_temp_dir());

        if ($realPath === false || $tempDir === false) {
            return;
        }

        $tempDirWithSep = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR;

        if (!str_starts_with($realPath, $tempDirWithSep)) {
            return;
        }

        unlink($realPath);
    }
}
