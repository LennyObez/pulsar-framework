<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Pulsar\Api\Api;
use Pulsar\I18n\TranslatorInterface;

/**
 * A validation violation that carries a translation key.
 *
 * Extends Violation with i18n support. The `translate()` method
 * resolves the key through the translator at render time.
 */
#[Api(since: '1.0.0')]
final readonly class TranslatableViolation extends Violation
{
    /**
     * @param array<string, mixed> $parameters ICU parameters for the message
     */
    public function __construct(
        string $field,
        string $rule,
        public string $translationKey,
        public array $parameters = [],
        string $code = '',
    ) {
        parent::__construct(
            field: $field,
            message: $translationKey,
            rule: $rule,
            code: $code,
        );
    }

    /**
     * Resolve the violation message through the translator.
     */
    public function translate(TranslatorInterface $translator, string $domain = 'validation'): string
    {
        return $translator->translate($this->translationKey, $this->parameters, domain: $domain);
    }
}
