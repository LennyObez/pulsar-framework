<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            theme: is_string($data['theme'] ?? null) ? $data['theme'] : 'default',
            errorClass: is_string($data['error_class'] ?? null) ? $data['error_class'] : 'form-error',
            labelClass: is_string($data['label_class'] ?? null) ? $data['label_class'] : 'form-label',
            inputClass: is_string($data['input_class'] ?? null) ? $data['input_class'] : 'form-input',
            errorSummaryClass: is_string($data['error_summary_class'] ?? null) ? $data['error_summary_class'] : 'form-error-summary',
        );
    }
}
