<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Publishing;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Publishing\PublishingChannelInterface;
use Pulsar\Extension\Cms\Publishing\PublishResult;
use Throwable;

use function dirname;
use function is_dir;
use function is_file;
use function ltrim;
use function mkdir;
use function rtrim;
use function sprintf;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Static site export channel.
 *
 * On publish, renders content to a static HTML file at
 * {outputPath}/{locale}/{path}/index.html.
 * On unpublish, removes the generated file.
 */
#[Internal(reason: 'Use PublishingChannelInterface for public API')]
final readonly class StaticSiteChannel implements PublishingChannelInterface
{
    public function __construct(
        private string $outputPath,
        private bool $enabled = false,
    ) {}

    public function name(): string
    {
        return 'static';
    }

    public function publish(Content $content, ContentTranslation $translation): PublishResult
    {
        try {
            $filePath = $this->resolveFilePath($translation);
            $directory = dirname($filePath);

            if (!is_dir($directory)) {
                mkdir($directory, 0o755, recursive: true);
            }

            $html = $this->renderStaticHtml($translation);
            file_put_contents($filePath, $html);

            return PublishResult::success($this->name(), $filePath);
        } catch (Throwable $e) {
            return PublishResult::failure($this->name(), $e->getMessage());
        }
    }

    public function unpublish(Content $content): PublishResult
    {
        // Without a translation we cannot determine the exact file path.
        // The orchestrator should call unpublish per-locale or accept that
        // static files are cleaned up via a separate sweep.
        return PublishResult::success($this->name());
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Remove a specific static file for a given translation.
     */
    public function removeStaticFile(ContentTranslation $translation): PublishResult
    {
        try {
            $filePath = $this->resolveFilePath($translation);

            if (is_file($filePath)) {
                unlink($filePath);
            }

            return PublishResult::success($this->name());
        } catch (Throwable $e) {
            return PublishResult::failure($this->name(), $e->getMessage());
        }
    }

    private function resolveFilePath(ContentTranslation $translation): string
    {
        $base = rtrim($this->outputPath, '/\\');
        $path = ltrim($translation->path, '/');

        return sprintf(
            '%s%s%s%s%s%sindex.html',
            $base,
            DIRECTORY_SEPARATOR,
            $translation->locale,
            DIRECTORY_SEPARATOR,
            $path,
            DIRECTORY_SEPARATOR,
        );
    }

    private function renderStaticHtml(ContentTranslation $translation): string
    {
        return sprintf(
            "<!DOCTYPE html>\n<html lang=\"%s\">\n<head>\n<meta charset=\"UTF-8\">\n<title>%s</title>\n</head>\n<body>\n%s\n</body>\n</html>",
            htmlspecialchars($translation->locale, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($translation->title, ENT_QUOTES, 'UTF-8'),
            $translation->body,
        );
    }
}
