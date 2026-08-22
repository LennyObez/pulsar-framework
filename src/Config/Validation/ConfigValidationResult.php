<?php

declare(strict_types=1);

namespace Pulsar\Config\Validation;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * Represents the result of validating a configuration DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConfigValidationResult
{
    /**
     * @param list<ConfigValidationError> $errors
     */
    public function __construct(
        public array $errors = [],
    ) {}

    #[NoDiscard]
    public function isValid(): bool
    {
        return $this->errors === [];
    }

    #[NoDiscard]
    public function errorCount(): int
    {
        return count($this->errors);
    }

    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function messages(): array
    {
        return array_map(
            static fn(ConfigValidationError $e): string => $e->message,
            $this->errors,
        );
    }

    #[NoDiscard]
    public function merge(self $other): self
    {
        return new self([...$this->errors, ...$other->errors]);
    }
}
