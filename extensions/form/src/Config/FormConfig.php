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
        $sub = static function (string $k) use ($data): array {
            $value = $data[$k] ?? null;

            /** @var array<string, mixed> */
            return is_array($value) ? $value : [];
        };

        return new self(
            csrf: CsrfFormConfig::fromArray($sub('csrf')),
            renderer: RendererConfig::fromArray($sub('renderer')),
            upload: UploadConfig::fromArray($sub('upload')),
            wizard: WizardFormConfig::fromArray($sub('wizard')),
        );
    }
}
