<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_any;
use function array_key_exists;
use function is_array;
use function is_int;
use function sprintf;

use const UPLOAD_ERR_OK;

/**
 * Uploaded file size must not exceed maximum bytes. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MaxFileSize implements RuleInterface
{
    public function __construct(
        private int $maxBytes,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($this->isValidSize($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must not be larger than %d bytes.',
                $field,
                $this->maxBytes,
            ),
            rule: $this->name(),
        );
    }

    private function isValidSize(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (array_any(['tmp_name', 'error', 'size', 'name', 'type'], static fn(string $key): bool => !array_key_exists($key, $value))) {
            return false;
        }

        if ($value['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        return is_int($value['size']) && $value['size'] <= $this->maxBytes;
    }

    #[Override]
    public function name(): string
    {
        return 'max_file_size';
    }
}
