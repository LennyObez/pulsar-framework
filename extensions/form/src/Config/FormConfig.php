<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

/**
 * Immutable configuration for the form extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FormConfig
{
    public function __construct(
        public CsrfFormConfig $csrf,
        public RendererConfig $renderer,
        public UploadConfig $upload,
        public WizardFormConfig $wizard,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $csrfData */
        $csrfData = is_array($data['csrf'] ?? null) ? $data['csrf'] : [];

        /** @var array<string, mixed> $rendererData */
        $rendererData = is_array($data['renderer'] ?? null) ? $data['renderer'] : [];

        /** @var array<string, mixed> $uploadData */
        $uploadData = is_array($data['upload'] ?? null) ? $data['upload'] : [];

        /** @var array<string, mixed> $wizardData */
        $wizardData = is_array($data['wizard'] ?? null) ? $data['wizard'] : [];

        return new self(
            csrf: CsrfFormConfig::fromArray($csrfData),
            renderer: RendererConfig::fromArray($rendererData),
            upload: UploadConfig::fromArray($uploadData),
            wizard: WizardFormConfig::fromArray($wizardData),
        );
    }
}
