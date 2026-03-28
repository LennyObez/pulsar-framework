<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     csrf?: array<string, mixed>,
     *     renderer?: array<string, mixed>,
     *     upload?: array<string, mixed>,
     *     wizard?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            csrf: CsrfFormConfig::fromArray($data['csrf'] ?? []),
            renderer: RendererConfig::fromArray($data['renderer'] ?? []),
            upload: UploadConfig::fromArray($data['upload'] ?? []),
            wizard: WizardFormConfig::fromArray($data['wizard'] ?? []),
        );
    }
}
