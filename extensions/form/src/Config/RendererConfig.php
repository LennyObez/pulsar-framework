<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Form renderer theming configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RendererConfig
{
    public function __construct(
        public string $theme,
        public string $errorClass,
        public string $labelClass,
        public string $inputClass,
        public string $errorSummaryClass,
    ) {}

    /**
     * @param array{
     *     theme?: string,
     *     error_class?: string,
     *     label_class?: string,
     *     input_class?: string,
     *     error_summary_class?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            theme: $data['theme'] ?? 'default',
            errorClass: $data['error_class'] ?? 'form-error',
            labelClass: $data['label_class'] ?? 'form-label',
            inputClass: $data['input_class'] ?? 'form-input',
            errorSummaryClass: $data['error_summary_class'] ?? 'form-error-summary',
        );
    }
}
