<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

/**
 * A single validation violation.
 */
readonly class Violation
{
    public function __construct(
        public string $field,
        public string $message,
        public string $rule,
    ) {}

    /**
     * @return array{field: string, message: string, rule: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'message' => $this->message,
            'rule' => $this->rule,
        ];
    }
}
