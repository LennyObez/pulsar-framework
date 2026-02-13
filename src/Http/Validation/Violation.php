<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Pulsar\Api\Api;

use function str_replace;
use function strtoupper;

/**
 * A single validation violation.
 */
#[Api(since: '1.0.0')]
readonly class Violation
{
    public string $code;

    public function __construct(
        public string $field,
        public string $message,
        public string $rule,
        string $code = '',
    ) {
        $this->code = $code !== ''
            ? $code
            : 'VALIDATION_' . strtoupper(str_replace(' ', '_', $this->rule));
    }

    /**
     * @return array{field: string, message: string, rule: string, code: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'message' => $this->message,
            'rule' => $this->rule,
            'code' => $this->code,
        ];
    }
}
