<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

use function is_string;

/**
 * Checks for filled honeypot fields in form submissions.
 *
 * A honeypot is a hidden form field that legitimate users never interact with.
 * Bots that auto-fill all fields will populate it, revealing themselves.
 * Returns a fake "pass" to avoid tipping off sophisticated bots that
 * monitor rejection patterns.
 */
#[Internal(reason: 'Use HoneypotDetectorInterface')]
final readonly class HoneypotDetector implements HoneypotDetectorInterface
{
    public function __construct(
        private string $fieldName = 'website_url',
    ) {}

    #[Override]
    public function name(): string
    {
        return 'honeypot';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        /** @var mixed $value */
        $value = $context->formFields[$this->fieldName] ?? null;

        if (is_string($value) && $value !== '') {
            return AntiSpamCheckResult::fail(
                $this->name(),
                50,
                'Honeypot field was filled: likely automated submission',
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
