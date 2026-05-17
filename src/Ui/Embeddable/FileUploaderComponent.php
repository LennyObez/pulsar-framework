<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use Override;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function implode;

use const ENT_QUOTES;

/**
 * Embeddable file uploader component.
 *
 * Renders a drag-and-drop file upload area with progress, preview,
 * and validation. Works as <pulsar-file-uploader> custom element.
 * @api
 */
#[Api(since: '1.0.0')]
final class FileUploaderComponent extends EmbeddableComponent
{
    private string $uploadUrl = '';
    private int $maxFileSizeMb = 10;
    private bool $multiple = true;

    /** @var list<string> */
    private array $acceptedTypes = [];

    #[Override]
    public function tagName(): string
    {
        return 'pulsar-file-uploader';
    }

    public function uploadUrl(string $url): self
    {
        $this->uploadUrl = $url;

        return $this;
    }

    public function maxFileSizeMb(int $mb): self
    {
        $this->maxFileSizeMb = $mb;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * @param list<string> $types MIME types (e.g. ['image/*', 'application/pdf'])
     */
    public function accept(array $types): self
    {
        $this->acceptedTypes = $types;

        return $this;
    }

    #[Override]
    public function renderInner(): string
    {
        $accept = $this->acceptedTypes !== []
            ? ' accept="' . htmlspecialchars(implode(',', $this->acceptedTypes), ENT_QUOTES, 'UTF-8') . '"'
            : '';

        $multiple = $this->multiple ? ' multiple' : '';

        $html = '<div class="pulsar-uploader-dropzone" role="button" tabindex="0" aria-label="Drop files here or click to upload">';
        $html .= '<p>Drag and drop files here, or click to browse</p>';
        $html .= '<p class="pulsar-uploader-hint">Max ' . $this->maxFileSizeMb . 'MB per file</p>';
        $html .= '<input type="file"' . $accept . $multiple . ' class="pulsar-uploader-input" aria-hidden="true" />';
        $html .= '</div>';
        $html .= '<div class="pulsar-uploader-preview" role="list" aria-label="Uploaded files"></div>';
        $html .= '<div class="pulsar-uploader-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden></div>';

        $this->prop('uploadUrl', $this->uploadUrl);
        $this->prop('maxFileSizeMb', $this->maxFileSizeMb);
        $this->prop('multiple', $this->multiple);

        return $html;
    }
}
