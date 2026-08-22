<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            theme: Coerce::string($data['theme'] ?? null, 'default'),
            errorClass: Coerce::string($data['error_class'] ?? null, 'form-error'),
            labelClass: Coerce::string($data['label_class'] ?? null, 'form-label'),
            inputClass: Coerce::string($data['input_class'] ?? null, 'form-input'),
            errorSummaryClass: Coerce::string($data['error_summary_class'] ?? null, 'form-error-summary'),
        );
    }
}
