<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Closure;
use Pulsar\Api\Api;

/**
 * A registered sensitive data pattern with its type and validation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SensitivePattern
{
    /**
     * @param SensitiveDataType    $type      Data classification
     * @param string               $regex     PCRE pattern for detection
     * @param (Closure(string): bool)|null $validator Optional post-match validator (e.g., Luhn check)
     */
    public function __construct(
        public SensitiveDataType $type,
        public string $regex,
        public ?Closure $validator = null,
    ) {}
}
