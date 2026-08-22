<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class FileDownloadBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'file-download';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri'],
                'filename' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'fileSize' => ['type' => 'string'],
            ],
            'required' => ['url', 'filename'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawUrl */
        $rawUrl = $data['url'] ?? null;
        /** @var mixed $rawFilename */
        $rawFilename = $data['filename'] ?? null;
        $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '', ENT_QUOTES, 'UTF-8');
        $filename = htmlspecialchars(is_string($rawFilename) ? $rawFilename : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $description */
        $description = $data['description'] ?? null;
        /** @var mixed $fileSize */
        $fileSize = $data['fileSize'] ?? null;

        $html = '<div class="file-download">';
        $html .= "<a href=\"$url\" download=\"$filename\" class=\"file-download__link\">$filename</a>";

        if (is_string($description) && $description !== '') {
            $html .= '<p class="file-download__description">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if (is_string($fileSize) && $fileSize !== '') {
            $html .= '<span class="file-download__size">' . htmlspecialchars($fileSize, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['url']) || !is_string($data['url'])) {
            $errors[] = 'url is required and must be a string';
        }

        if (!isset($data['filename']) || !is_string($data['filename'])) {
            $errors[] = 'filename is required and must be a string';
        }

        return $errors;
    }
}
