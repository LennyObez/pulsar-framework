<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Exception;

use NoDiscard;

/**
 * Thrown when resource data fails validation.
 */
final class ResourceValidationException extends AdminException
{
    /** @var list<array{field: string, message: string, rule: string}> */
    public readonly array $violations;

    /**
     * @param list<array{field: string, message: string, rule: string}> $violations
     */
    private function __construct(string $message, array $violations)
    {
        parent::__construct($message);
        $this->violations = $violations;
    }

    /**
     * @param list<array{field: string, message: string, rule: string}> $violations
     */
    #[NoDiscard]
    public static function fromViolations(array $violations): self
    {
        $count = count($violations);
        return new self(
            "Validation failed with {$count} error(s)",
            $violations,
        );
    }
}
